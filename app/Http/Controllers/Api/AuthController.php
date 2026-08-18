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
                    'answer' => $captcha['text'],
                ],
                'expires_in' => $ttlMinutes * 60,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        // ===== DEBUG: LOG KONFIGURASI MAIL (hanya di environment local) =====
        if (app()->environment('local')) {
            \Log::info('📧 [MAIL] default: ' . config('mail.default'));
            \Log::info('📧 [MAIL] smtp url: ' . config('mail.mailers.smtp.url'));
        }

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
        $user = $this->resolveUserFromCredentials($username, $password);
        if (! $user) {
            $this->logLoginActivity(null, $username, 'failed', 'user_not_found', $request);

            return response()->json([
                'success' => false,
                'message' => 'Username, password, atau captcha tidak valid',
            ], 401);
        }

        if ($this->shouldRequireOtp($user)) {
            $otp = $this->resolveOtpForUser($user);
            Cache::put("otp:{$user->username}", $otp, now()->addMinutes(5));

            // Catatan: kode OTP asli SENGAJA tidak pernah ditulis ke log,
            // walau di environment local - supaya log server tidak jadi
            // celah untuk login tanpa akses email.
            if (app()->environment('local')) {
                \Log::info('🔐 [OTP] Mengirim OTP ke user: ' . $user->username);
            }

            // ===== PENGIRIMAN OTP (DUA OPSI) =====
            // OPSI A: Menggunakan OtpMail (seharusnya berhasil, tapi kadang bermasalah)
            try {
                Mail::to($user->email)->send(new OtpMail($otp, $user->username, 5));
                \Log::info('✅ [OTP] OtpMail berhasil dikirim ke ' . $user->email);
            } catch (\Throwable $e) {
                \Log::error('❌ [OTP] OtpMail gagal: ' . $e->getMessage());
                // OPSI B: Fallback ke Mail::raw jika OtpMail gagal
                try {
                    Mail::raw("Kode OTP Anda: {$otp}", function ($message) use ($user) {
                        $message->to($user->email)->subject('Kode OTP Anda');
                    });
                    \Log::info('✅ [OTP] Mail::raw (fallback) berhasil dikirim ke ' . $user->email);
                } catch (\Throwable $e2) {
                    \Log::error('❌ [OTP] Mail::raw juga gagal: ' . $e2->getMessage());
                }
            }

            $activeMinutes = (int) config('sso.session_active_minutes', 15);
            $refreshDays = (int) config('sso.refresh_token_days', 7);
            $now = now();

            $session = SsoSession::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'refresh_token' => $this->generateRefreshToken(),
                'refresh_expires_at' => $now->copy()->addDays($refreshDays),
                'expires_at' => $now->copy()->addMinutes($activeMinutes),
                'activation_code' => null,
                'captcha_id' => null,
                'captcha_answer' => null,
            ]);

            $this->logLoginActivity($user->id, $user->username, 'pending_otp', null, $request);

            return response()->json([
                'success' => true,
                'message' => 'OTP dikirim ke email Anda. Silakan verifikasi untuk melanjutkan login.',
                'data' => [
                    'requires_otp' => true,
                    'session_id' => $session->id,
                    'otp' => app()->environment(['local', 'testing']) ? $otp : null,
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
        $sessionId = $request->input('session_id');
        $username = $request->input('username');
        $otp = $request->input('otp');

        if (! $sessionId || ! $username || ! $otp) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID, username, dan OTP wajib diisi',
            ], 400);
        }

        $session = SsoSession::find($sessionId);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session tidak ditemukan',
            ], 404);
        }

        if ($session->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Session sudah berstatus {$session->status}",
            ], 400);
        }

        $user = User::find($session->user_id);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna tidak ditemukan',
            ], 404);
        }

        if ($user->username !== $username) {
            return response()->json([
                'success' => false,
                'message' => 'Username tidak sesuai dengan session',
            ], 400);
        }

        $maxOtpAttempts = (int) config('sso.max_otp_attempts', 5);

        if ($session->activation_attempts >= $maxOtpAttempts) {
            $session->update(['status' => 'expired']);
            Cache::forget("otp:{$user->username}");

            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan OTP salah. Silakan login ulang.',
            ], 429);
        }

        $expectedOtp = Cache::get("otp:{$user->username}");
        if ($expectedOtp === null) {
            return response()->json([
                'success' => false,
                'message' => 'OTP tidak valid atau sudah kadaluarsa',
            ], 400);
        }

        if ((string) $otp !== (string) $expectedOtp) {
            $newAttempts = (int) $session->activation_attempts + 1;
            $session->update(['activation_attempts' => $newAttempts]);

            return response()->json([
                'success' => false,
                'message' => 'OTP tidak sesuai',
                'remaining_attempts' => max(0, $maxOtpAttempts - $newAttempts),
            ], 400);
        }

        Cache::forget("otp:{$user->username}");

        $activeMinutes = (int) config('sso.session_active_minutes', 15);
        $refreshDays = (int) config('sso.refresh_token_days', 7);
        $now = now();

        $session->update([
            'status' => 'active',
            'activation_attempts' => 0,
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
     * Kirim ulang kode OTP untuk sesi 'pending' yang sama (dipanggil dari
     * tombol "Kirim ulang kode OTP" di halaman login).
     */
    public function resendOtp(Request $request)
    {
        $sessionId = $request->input('session_id');
        $username = $request->input('username');

        if (! $sessionId || ! $username) {
            return response()->json([
                'success' => false,
                'message' => 'Session ID dan username wajib diisi',
            ], 400);
        }

        $session = SsoSession::find($sessionId);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Session tidak ditemukan'], 404);
        }

        if ($session->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Session sudah berstatus {$session->status}. Silakan login ulang.",
            ], 400);
        }

        $user = User::find($session->user_id);
        if (! $user || $user->username !== $username) {
            return response()->json(['success' => false, 'message' => 'Username tidak sesuai dengan session'], 400);
        }

        // Batasi frekuensi kirim ulang di sisi server (jangan cuma andalkan cooldown di frontend).
        $cooldownSeconds = (int) config('sso.otp_resend_cooldown_seconds', 30);
        $cooldownKey = "otp_resend_cooldown:{$session->id}";

        if (Cache::has($cooldownKey)) {
            $retryAfter = Cache::get($cooldownKey) - now()->timestamp;

            return response()->json([
                'success' => false,
                'message' => 'Mohon tunggu sebelum meminta kode OTP baru.',
                'retry_after' => max(1, $retryAfter),
            ], 429);
        }

        $otp = $this->resolveOtpForUser($user);
        Cache::put("otp:{$user->username}", $otp, now()->addMinutes(5));
        Cache::put($cooldownKey, now()->addSeconds($cooldownSeconds)->timestamp, now()->addSeconds($cooldownSeconds));

        // Reset counter percobaan gagal setiap kali kode baru dikirim.
        $session->update(['activation_attempts' => 0]);

        try {
            Mail::to($user->email)->send(new OtpMail($otp, $user->username, 5));
        } catch (\Throwable $e) {
            \Log::error('❌ [OTP] Gagal mengirim ulang OTP: ' . $e->getMessage());
            try {
                Mail::raw("Kode OTP Anda: {$otp}", function ($message) use ($user) {
                    $message->to($user->email)->subject('Kode OTP Anda');
                });
            } catch (\Throwable $e2) {
                \Log::error('❌ [OTP] Mail::raw fallback juga gagal: ' . $e2->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim ulang OTP. Silakan coba beberapa saat lagi.',
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Kode OTP baru telah dikirim ke email Anda.',
            'data' => [
                'session_id' => $session->id,
                'otp' => app()->environment(['local', 'testing']) ? $otp : null,
            ],
        ]);
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

        $query = SsoSession::query();

        if ($sessionId) {
            $query->where('id', $sessionId);
        } else {
            // Fallback: frontend biasanya cuma menyimpan token, bukan session_id.
            $authHeader = $request->header('Authorization', '');
            $token = Str::startsWith($authHeader, 'Bearer ')
                ? substr($authHeader, 7)
                : ($request->input('token') ?: null);

            if (! $token) {
                return response()->json(['success' => false, 'message' => 'session_id atau token wajib diisi'], 400);
            }

            $query->where('refresh_token', $token);
        }

        $updated = $query->update(['status' => 'expired', 'refresh_token' => null]);

        if ($updated === 0) {
            // Tetap dianggap sukses: kalaupun session sudah tidak ada/expired,
            // hasil akhir yang diinginkan (client tidak lagi punya sesi aktif) tercapai.
            return response()->json(['success' => true, 'message' => 'Session sudah tidak aktif.']);
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
        // OTP dilewati HANYA kalau user ini masih punya sesi yang "diingat":
        // refresh_token belum di-null-kan (belum pernah pencet Logout) DAN
        // belum melewati masa berlaku refresh_expires_at (7 hari).
        //
        // - Idle timeout (expires_at 15 menit lewat) TIDAK menghapus
        //   refresh_token -> sesi tetap dianggap "diingat" -> login ulang
        //   tanpa OTP.
        // - Logout eksplisit MENGHAPUS refresh_token (lihat logout()) ->
        //   tidak ada sesi yang cocok -> wajib OTP lagi.
        // - First-time login (belum pernah ada sesi sama sekali) -> tidak
        //   ada sesi yang cocok -> wajib OTP.
        $hasRememberedSession = SsoSession::where('user_id', $user->id)
            ->whereNotNull('refresh_token')
            ->where('refresh_expires_at', '>', now())
            ->exists();

        return ! $hasRememberedSession;
    }

    /**
     * Menghasilkan OTP acak 6 digit.
     */
    private function resolveOtpForUser(User $user): string
    {
        // Generate OTP acak 6 digit (100000 - 999999)
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function resolveUserFromCredentials(string $username, string $password): ?User
    {
        $dummyUsers = [
            'admin_simrs' => ['password' => '12#56*DS', 'email' => 'admin.simrs@example.com', 'role' => 'admin'],
            'dokter_amino' => ['password' => '11#22*AA', 'email' => 'dokter.amino@example.com', 'role' => 'dokter'],
            'petugas_lapor' => ['password' => '33#44*PL', 'email' => 'petugas.lapor@example.com', 'role' => 'petugas'],
            'manager_wbs' => ['password' => '55#66*MW', 'email' => 'manager.wbs@example.com', 'role' => 'manager'],
            'super_user' => ['password' => '77#88*SU', 'email' => 'girlclown666@gmail.com', 'role' => 'super_user'],
        ];

        if (! array_key_exists($username, $dummyUsers)) {
            return null;
        }

        if (($dummyUsers[$username]['password'] ?? null) !== $password) {
            return null;
        }

        return User::updateOrCreate(
            ['username' => $username],
            [
                'email' => $dummyUsers[$username]['email'],
                'role' => $dummyUsers[$username]['role'],
                'password_hash' => Hash::make($password),
            ]
        );
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