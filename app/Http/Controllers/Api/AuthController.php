<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\LoginActivity;
use App\Models\SsoSession;
use App\Models\User;
use App\Support\CaptchaGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_ACTIVATION_ATTEMPTS_FALLBACK = 5;

    public function health()
    {
        return response()->json(['success' => true, 'message' => 'SSO backend is running']);
    }

    /**
     * Captcha untuk halaman login: berdiri sendiri, tampil bersama form
     * username & password (belum menunggu password diverifikasi dulu).
     * Disimpan sementara di cache (satu kali pakai, TTL singkat) - setara
     * dengan Map di memori pada versi Node.js.
     */
    public function captcha()
    {
        $captcha = CaptchaGenerator::generate();
        $id = (string) Str::uuid();
        $ttlMinutes = (int) config('sso.login_captcha_ttl_minutes', 5);

        Cache::put("login_captcha:{$id}", $captcha['text'], now()->addMinutes($ttlMinutes));

        return response()->json([
            'success' => true,
            'data' => [
                'captcha' => [
                    'id' => $id,
                    'svg' => $captcha['svg'],
                ],
                'expires_in' => $ttlMinutes * 60,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $captchaId = $request->input('captcha_id');
        $captchaAnswer = $request->input('captcha_answer');

        if (! $username || ! $password || ! $captchaId || ! $captchaAnswer) {
            return response()->json([
                'success' => false,
                'message' => 'Username, password, dan captcha wajib diisi',
            ], 400);
        }

        // 1. Cek captcha lebih dulu (satu kali pakai) sebelum menyentuh data user.
        $cacheKey = "login_captcha:{$captchaId}";
        $expectedAnswer = Cache::get($cacheKey);
        Cache::forget($cacheKey); // one-time use, baik cocok maupun tidak

        if ($expectedAnswer === null) {
            $this->logLoginActivity(null, $username, 'failed', 'captcha_expired', $request);

            return response()->json([
                'success' => false,
                'message' => 'Captcha kadaluarsa. Silakan muat ulang captcha.',
            ], 400);
        }

        if ((string) $captchaAnswer !== (string) $expectedAnswer) {
            $this->logLoginActivity(null, $username, 'failed', 'captcha_failed', $request);

            return response()->json([
                'success' => false,
                'message' => 'Username, password, atau captcha tidak valid',
            ], 400);
        }

        // 2. Baru cek username/password.
        $user = User::where('username', $username)->first();
        if (! $user) {
            $this->logLoginActivity(null, $username, 'failed', 'user_not_found', $request);

            return response()->json([
                'success' => false,
                'message' => 'Username, password, atau captcha tidak valid',
            ], 401);
        }

        if (! Hash::check($password, $user->password_hash)) {
            $this->logLoginActivity($user->id, $username, 'failed', 'wrong_password', $request);

            return response()->json([
                'success' => false,
                'message' => 'Username, password, atau captcha tidak valid',
            ], 401);
        }

        if ($this->shouldRequireOtp($user)) {
            $otp = (string) random_int(100000, 999999);
            Cache::put("otp:{$user->username}", $otp, now()->addMinutes(5));

            try {
                Mail::to($user->email)->send(new OtpMail($otp, $user->username, 5));
            } catch (\Throwable $e) {
                report($e);
            }

            $this->logLoginActivity($user->id, $user->username, 'pending_otp', null, $request);

            return response()->json([
                'success' => true,
                'message' => 'OTP dikirim ke email Anda. Silakan verifikasi untuk melanjutkan login.',
                'data' => [
                    'requires_otp' => true,
                    'otp' => app()->environment('local') ? $otp : null,
                    'user' => $this->userPayload($user),
                ],
            ], 200);
        }

        // 3. Semua valid -> langsung buat sesi aktif (tanpa tahap aktivasi terpisah).
        $activeMinutes = (int) config('sso.session_active_minutes', 15);
        $refreshDays = (int) config('sso.refresh_token_days', 7);
        $now = now();

        $session = SsoSession::create([
            'user_id' => $user->id,
            'status' => 'active',
            'refresh_token' => $this->generateRefreshToken(),
            'refresh_expires_at' => $now->copy()->addDays($refreshDays),
            'expires_at' => $now->copy()->addMinutes($activeMinutes),
        ]);

        $this->logLoginActivity($user->id, $user->username, 'success', null, $request);

        return response()->json([
            'success' => true,
            'data' => [
                'refresh_token' => $session->refresh_token,
                'expires_in' => $activeMinutes * 60,
                'session_id' => $session->id,
                'status' => 'active',
                'requires_otp' => false,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    public function verifyOtp(Request $request)
    {
        $username = $request->input('username');
        $otp = $request->input('otp');

        if (! $username || ! $otp) {
            return response()->json([
                'success' => false,
                'message' => 'Username dan OTP wajib diisi',
            ], 400);
        }

        $user = User::where('username', $username)->first();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna tidak ditemukan',
            ], 404);
        }

        $expectedOtp = Cache::get("otp:{$user->username}");
        if ($expectedOtp === null) {
            return response()->json([
                'success' => false,
                'message' => 'OTP tidak valid atau sudah kadaluarsa',
            ], 400);
        }

        if ((string) $otp !== (string) $expectedOtp) {
            return response()->json([
                'success' => false,
                'message' => 'OTP tidak sesuai',
            ], 400);
        }

        Cache::forget("otp:{$user->username}");

        $activeMinutes = (int) config('sso.session_active_minutes', 15);
        $refreshDays = (int) config('sso.refresh_token_days', 7);
        $now = now();

        $session = SsoSession::create([
            'user_id' => $user->id,
            'status' => 'active',
            'refresh_token' => $this->generateRefreshToken(),
            'refresh_expires_at' => $now->copy()->addDays($refreshDays),
            'expires_at' => $now->copy()->addMinutes($activeMinutes),
        ]);

        $this->logLoginActivity($user->id, $user->username, 'success', 'otp_verified', $request);

        return response()->json([
            'success' => true,
            'data' => [
                'refresh_token' => $session->refresh_token,
                'expires_in' => $activeMinutes * 60,
                'session_id' => $session->id,
                'status' => 'active',
                'requires_otp' => false,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    /**
     * Endpoint aktivasi sesi 'pending' (kode aktivasi + captcha tersimpan di
     * baris session). Dipertahankan untuk kompatibilitas, meski alur login
     * saat ini langsung membuat sesi 'active' tanpa tahap pending.
     */
    public function activate(Request $request)
    {
        $sessionId = $request->input('session_id');
        $activationCode = $request->input('activation_code');
        $captchaId = $request->input('captcha_id');
        $captchaAnswer = $request->input('captcha_answer');

        if (! $sessionId || ! $activationCode || ! $captchaId || ! $captchaAnswer) {
            return response()->json(['success' => false, 'message' => 'Semua field wajib diisi'], 400);
        }

        $session = SsoSession::find($sessionId);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Session tidak ditemukan'], 404);
        }

        if ($session->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Session sudah berstatus {$session->status}",
            ], 400);
        }

        if ($session->expires_at && now()->greaterThan($session->expires_at)) {
            $session->update(['status' => 'expired']);

            return response()->json(['success' => false, 'message' => 'Session kadaluarsa. Silakan login ulang.'], 401);
        }

        $maxAttempts = (int) config('sso.max_activation_attempts', self::MAX_ACTIVATION_ATTEMPTS_FALLBACK);

        if ($session->activation_attempts >= $maxAttempts) {
            $session->update(['status' => 'expired']);

            return response()->json(['success' => false, 'message' => 'Terlalu banyak percobaan. Session dikunci.'], 429);
        }

        $captchaMatch = $session->captcha_id === $captchaId
            && (string) $captchaAnswer === (string) $session->captcha_answer;
        $codeMatch = (string) $activationCode === (string) $session->activation_code;

        if (! $captchaMatch || ! $codeMatch) {
            $newAttempts = (int) $session->activation_attempts + 1;
            $session->update(['activation_attempts' => $newAttempts]);

            return response()->json([
                'success' => false,
                'message' => 'Kode aktivasi atau captcha tidak sesuai.',
                'remaining_attempts' => $maxAttempts - $newAttempts,
            ], 400);
        }

        $activeMinutes = (int) config('sso.session_active_minutes', 15);
        $refreshDays = (int) config('sso.refresh_token_days', 7);
        $now = now();

        $session->update([
            'status' => 'active',
            'activation_code' => null,
            'captcha_id' => null,
            'captcha_answer' => null,
            'refresh_token' => $this->generateRefreshToken(),
            'refresh_expires_at' => $now->copy()->addDays($refreshDays),
            'expires_at' => $now->copy()->addMinutes($activeMinutes),
        ]);

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'data' => [
                'refresh_token' => $session->refresh_token,
                'expires_in' => $activeMinutes * 60,
                'session_id' => $session->id,
                'status' => 'active',
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function session(Request $request)
    {
        $sessionId = $request->input('session_id');
        if (! $sessionId) {
            return response()->json(['success' => false, 'message' => 'session_id wajib diisi'], 400);
        }

        $session = SsoSession::find($sessionId);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Session tidak ditemukan'], 404);
        }

        if ($session->status === 'active' && $session->expires_at && now()->greaterThan($session->expires_at)) {
            $session->update(['status' => 'expired']);
        }

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'status' => $session->status,
                'user' => $this->userPayload($user),
                'created_at' => optional($session->created_at)->toJSON(),
                'expires_at' => optional($session->expires_at)->toJSON(),
                'expires_in' => $session->expires_at
                    ? $this->minutesRemaining($session->expires_at)
                    : null,
            ],
        ]);
    }

    public function validateToken(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        $token = Str::startsWith($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $authHeader;

        if (! $token && $request->input('token')) {
            $token = $request->input('token');
        }

        if (! $token) {
            return response()->json(['success' => false, 'valid' => false, 'message' => 'Token tidak ditemukan'], 401);
        }

        $session = SsoSession::where('refresh_token', $token)->where('status', 'active')->first();
        if (! $session) {
            return response()->json(['success' => false, 'valid' => false, 'message' => 'Token tidak valid atau session tidak aktif'], 401);
        }

        if ($session->expires_at && now()->greaterThan($session->expires_at)) {
            $session->update(['status' => 'expired']);

            return response()->json(['success' => false, 'valid' => false, 'message' => 'Session expired'], 401);
        }

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'valid' => true,
            'data' => [
                'session_id' => $session->id,
                'time_remaining' => $this->minutesRemaining($session->expires_at),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $refreshToken = $request->input('refresh_token');
        if (! $refreshToken) {
            return response()->json(['success' => false, 'message' => 'refresh_token wajib diisi'], 400);
        }

        $session = SsoSession::where('refresh_token', $refreshToken)->first();
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Refresh token tidak valid'], 401);
        }

        if ($session->refresh_expires_at && now()->greaterThan($session->refresh_expires_at)) {
            $session->update(['status' => 'expired']);

            return response()->json(['success' => false, 'message' => 'Refresh token kadaluarsa. Silakan login ulang.'], 401);
        }

        $activeMinutes = (int) config('sso.session_active_minutes', 15);
        $session->update([
            'status' => 'active',
            'expires_at' => now()->addMinutes($activeMinutes),
        ]);

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'data' => [
                'refresh_token' => $refreshToken,
                'expires_in' => $activeMinutes * 60,
                'session_id' => $session->id,
                'status' => 'active',
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $sessionId = $request->input('session_id');
        if (! $sessionId) {
            return response()->json(['success' => false, 'message' => 'session_id wajib diisi'], 400);
        }

        $updated = SsoSession::where('id', $sessionId)->update(['status' => 'expired', 'refresh_token' => null]);

        if ($updated === 0) {
            return response()->json(['success' => false, 'message' => 'Session tidak ditemukan'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Logout berhasil. Session dan token dinonaktifkan.']);
    }

    private function userPayload(?User $user): array
    {
        if (! $user) {
            return ['username' => null, 'email' => null, 'role' => null, 'modul_akses' => []];
        }

        return [
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'modul_akses' => $user->modules()->get(['modules.code', 'modules.name', 'modules.url'])
                ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'url' => $m->url])
                ->values(),
        ];
    }

    private function shouldRequireOtp(User $user): bool
    {
        return $user->role === 'super_user' && filled($user->email);
    }

    private function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    /**
     * Setara dengan toMinutes(ms) pada versi Node.js: sisa waktu dalam menit,
     * dibulatkan, tidak pernah negatif.
     */
    private function minutesRemaining(?Carbon $expiresAt): int
    {
        if (! $expiresAt) {
            return 0;
        }

        $now = now();
        if ($now->greaterThanOrEqualTo($expiresAt)) {
            return 0;
        }

        $total = (int) round($now->diffInSeconds($expiresAt) / 60);

        return $total > 0 ? $total : 0;
    }

    private function logLoginActivity(?string $userId, ?string $username, string $status, ?string $reason, Request $request): void
    {
        try {
            LoginActivity::create([
                'user_id' => $userId,
                'username' => $username,
                'status' => $status,
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
