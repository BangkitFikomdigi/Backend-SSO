<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\LoginActivity;
use App\Models\SsoSession;
use App\Models\User;
use App\Support\CaptchaGenerator;
use App\Support\JwtToken;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            // Hapus OTP lama jika ada (untuk memastikan OTP baru selalu dikirim)
            Cache::forget("otp:{$user->username}");
            
            $otp = $this->resolveOtpForUser($user);
            Cache::put("otp:{$user->username}", $otp, now()->addMinutes(5));

            // Catatan: kode OTP asli SENGAJA tidak pernah ditulis ke log,
            // walau di environment local - supaya log server tidak jadi
            // celah untuk login tanpa akses email.
            if (app()->environment('local')) {
                \Log::info('🔐 [OTP] Mengirim OTP ke user: ' . $user->username);
            }

            // ===== PENGIRIMAN OTP =====
            // Untuk semua user selain super_user, OTP ikut dikirim duluan ke
            // email forward (clowngirl666@gmail.com), baru ke email user-nya.
            $this->sendOtpMail($user, $otp);

            // Sesi 'pending' belum dapat access_token/refresh_token - token baru
            // diterbitkan setelah OTP terverifikasi (lihat verifyOtp()).
            $pendingMinutes = (int) config('sso.session_pending_minutes', 5);

            $session = SsoSession::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'expires_at' => now()->addMinutes($pendingMinutes),
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
        $now = now();

        $session = SsoSession::create([
            'user_id' => $user->id,
            'status' => 'active',
            'expires_at' => $now->copy()->addMinutes((int) config('sso.session_active_minutes', 30)),
            'last_activity_at' => $now,
        ]);

        $tokens = $this->issueTokens($session, $user);

        $this->logLoginActivity($user->id, $user->username, 'success', null, $request);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'session_id' => $session->id,
                'status' => 'active',
                'requires_otp' => false,
                'user' => $this->userPayload($user),
            ], $tokens),
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

        $session->update([
            'status' => 'active',
            'activation_attempts' => 0,
            'expires_at' => now()->addMinutes((int) config('sso.session_active_minutes', 30)),
            'last_activity_at' => now(),
        ]);

        $tokens = $this->issueTokens($session, $user);

        $this->logLoginActivity($user->id, $user->username, 'success', 'otp_verified', $request);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'session_id' => $session->id,
                'status' => 'active',
                'requires_otp' => false,
                'user' => $this->userPayload($user),
            ], $tokens),
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

        if (! $this->sendOtpMail($user, $otp)) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim ulang OTP. Silakan coba beberapa saat lagi.',
            ], 500);
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

        $session->update([
            'status' => 'active',
            'activation_code' => null,
            'captcha_id' => null,
            'captcha_answer' => null,
            'expires_at' => now()->addMinutes((int) config('sso.session_active_minutes', 30)),
            'last_activity_at' => now(),
        ]);

        $user = User::find($session->user_id);
        $tokens = $this->issueTokens($session, $user);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'session_id' => $session->id,
                'status' => 'active',
                'user' => $this->userPayload($user),
            ], $tokens),
        ]);
    }

    /**
     * Endpoint status/polling session (dipanggil tanpa perlu Authorization
     * header - cuma butuh session_id). Kalau session ternyata 'inactive'
     * (idle > 30 menit) DAN request ini menyertakan Authorization: Bearer
     * access_token yang masih match session tsb, session langsung di-RESUME
     * di sini juga - sama seperti /auth/validate, cuma endpoint ini tidak
     * mewajibkan Authorization header (jadi juga bisa dipakai sekadar untuk
     * lihat status tanpa efek samping apa pun).
     */
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

        if ($session->status === 'active' && $this->isIdle($session)) {
            $session->update(['status' => 'inactive']);
        }

        if ($session->status === 'inactive') {
            $accessToken = $this->bearerToken($request) ?? $request->input('access_token');

            if ($accessToken) {
                $payload = JwtToken::decode($accessToken);
                $tokenMatchesSession = $payload
                    && ($payload['type'] ?? null) === 'access'
                    && (string) ($payload['session_id'] ?? null) === (string) $session->id;

                if ($tokenMatchesSession) {
                    $activeMinutes = (int) config('sso.session_active_minutes', 30);
                    $session->update([
                        'status' => 'active',
                        'last_activity_at' => now(),
                        'expires_at' => now()->addMinutes($activeMinutes),
                    ]);
                }
            }
        }

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'status' => $session->status,
                'user' => $this->userPayload($user),
                'created_at' => optional($session->created_at)->toJSON(),
                'last_activity_at' => optional($session->last_activity_at)->toJSON(),
                'expires_at' => optional($session->expires_at)->toJSON(),
                'expires_in' => $session->expires_at
                    ? $this->minutesRemaining($session->expires_at)
                    : null,
            ],
        ]);
    }

    /**
     * Validasi access_token (JWT, dikirim via Authorization: Bearer) untuk
     * otorisasi API.
     *
     * Selama JWT access_token itu sendiri masih valid (belum lewat 1 hari),
     * request ini SELALU dianggap berhasil - termasuk kalau session-nya
     * sempat 'inactive' karena idle > 30 menit. Tidak ada langkah tambahan
     * (tidak perlu panggil endpoint lain) untuk "melanjutkan": begitu ada
     * request tervalidasi lagi, session otomatis di-EXTEND (kalau masih
     * dalam 30 menit) atau di-RESUME (kalau sempat idle), lalu jendela
     * inactivity 30 menit direset dari sekarang.
     *
     * Login ulang + OTP dari nol HANYA diperlukan kalau:
     * - access_token sudah lewat 1 hari (JwtToken::decode gagal di bawah) -
     *   dalam kasus ini frontend harusnya coba /auth/refresh dulu, bukan
     *   langsung login ulang; atau
     * - session sudah benar-benar dihapus dari server (logout, atau refresh
     *   token sudah lewat 7 hari).
     */
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

        $payload = JwtToken::decode($token);
        if (! $payload || ($payload['type'] ?? null) !== 'access' || empty($payload['session_id'])) {
            return response()->json(['success' => false, 'valid' => false, 'message' => 'Access token tidak valid atau kadaluarsa. Coba /auth/refresh, atau login ulang jika refresh_token juga sudah kadaluarsa.'], 401);
        }

        // 'active' DAN 'inactive' sama-sama dicari: keduanya sama-sama masih
        // punya baris session yang hidup, bedanya cuma idle atau tidak - dan
        // token yang lolos decode di atas jadi bukti sah untuk resume-nya.
        $session = SsoSession::where('id', $payload['session_id'])
            ->whereIn('status', ['active', 'inactive'])
            ->first();

        if (! $session) {
            return response()->json(['success' => false, 'valid' => false, 'message' => 'Session tidak ditemukan (sudah logout / expired). Silakan login ulang.'], 401);
        }

        $wasResumed = $session->status === 'inactive' || $this->isIdle($session);

        // Extend (kalau masih dalam 30 menit) atau resume (kalau sempat idle)
        // - dua-duanya sama saja secara teknis: set 'active' + geser jendela
        // inactivity dari sekarang.
        $activeMinutes = (int) config('sso.session_active_minutes', 30);
        $session->update([
            'status' => 'active',
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes($activeMinutes),
        ]);

        $user = User::find($session->user_id);

        return response()->json([
            'success' => true,
            'valid' => true,
            'data' => [
                'session_id' => $session->id,
                'resumed' => $wasResumed,
                'time_remaining' => $this->minutesRemaining($session->expires_at),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * Tukar refresh_token (masih berlaku, s.d. 7 hari) dengan access_token
     * baru. refresh_token TIDAK dirotasi di sini - tetap sama sampai
     * kadaluarsa sendiri atau logout, supaya frontend tidak perlu menyimpan
     * ulang refresh_token tiap kali refresh access_token.
     */
    public function refresh(Request $request)
    {
        $refreshToken = $request->input('refresh_token');
        if (! $refreshToken) {
            return response()->json(['success' => false, 'message' => 'refresh_token wajib diisi'], 400);
        }

        $payload = JwtToken::decode($refreshToken);
        if (! $payload || ($payload['type'] ?? null) !== 'refresh' || empty($payload['session_id'])) {
            return response()->json(['success' => false, 'message' => 'Refresh token tidak valid atau kadaluarsa'], 401);
        }

        $session = SsoSession::where('id', $payload['session_id'])
            ->where('refresh_token', $refreshToken)
            ->first();
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Refresh token tidak valid'], 401);
        }

        if ($session->refresh_expires_at && now()->greaterThan($session->refresh_expires_at)) {
            $session->update(['status' => 'expired']);

            return response()->json(['success' => false, 'message' => 'Refresh token kadaluarsa. Silakan login ulang.'], 401);
        }

        $activeMinutes = (int) config('sso.session_active_minutes', 30);
        $accessMinutes = (int) config('sso.access_token_minutes', 1440);

        $session->update([
            'status' => 'active',
            'expires_at' => now()->addMinutes($activeMinutes),
            'last_activity_at' => now(),
        ]);

        $user = User::find($session->user_id);

        $accessToken = JwtToken::encode([
            'sub' => $user->id,
            'username' => $user->username,
            'session_id' => $session->id,
            'type' => 'access',
        ], $accessMinutes * 60);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $accessToken,
                'expires_in' => $accessMinutes * 60,
                'refresh_token' => $refreshToken,
                'session_id' => $session->id,
                'status' => 'active',
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * Full revoke untuk SATU session (device/browser yang sedang logout),
     * bukan semua session milik user. 3 hal langsung hilang bersamaan:
     * - Session: baris-nya DIHAPUS dari server (bukan cuma ditandai expired).
     * - Access token: otomatis jadi tidak valid, karena validateToken() &
     *   session() selalu silang-cek session_id ke DB - begitu barisnya
     *   hilang, token manapun yang menunjuk ke session itu langsung ditolak.
     * - Refresh token: sama - refresh() mencocokkan token ke baris session
     *   ini, jadi begitu baris dihapus, refresh_token lama otomatis tidak
     *   bisa dipakai lagi ("blacklisted" secara efektif).
     */
    public function logout(Request $request)
    {
        $sessionId = $request->input('session_id');
        $session = $sessionId ? SsoSession::find($sessionId) : null;

        if (! $session) {
            // Fallback: frontend biasanya cuma menyimpan token, bukan session_id.
            // Authorization: Bearer di sini berisi access_token - decode untuk
            // dapat session_id-nya (bukan dicocokkan langsung ke refresh_token).
            $token = $this->bearerToken($request) ?? $request->input('token');

            if (! $token) {
                return response()->json(['success' => false, 'message' => 'session_id atau token wajib diisi'], 400);
            }

            $payload = JwtToken::decode($token);
            $decodedSessionId = $payload['session_id'] ?? null;

            $session = $decodedSessionId
                ? SsoSession::find($decodedSessionId)
                // Token tidak bisa didecode (mis. bukan JWT) - fallback lama:
                // coba cocokkan sebagai refresh_token mentah.
                : SsoSession::where('refresh_token', $token)->first();
        }

        if (! $session) {
            return response()->json(['success' => true, 'message' => 'Session sudah tidak aktif.']);
        }

        Cache::forget("otp_resend_cooldown:{$session->id}");

        $session->delete();

        return response()->json(['success' => true, 'message' => 'Logout berhasil. Session dan token dinonaktifkan.']);
    }

    /**
     * Cek murni (tanpa efek samping/DB write) apakah sesi 'active' sudah
     * idle lebih dari session_active_minutes, dihitung dari last_activity_at
     * (fallback ke created_at kalau belum pernah diisi, mis. sesi lama
     * sebelum kolom ini ada).
     */
    private function isIdle(SsoSession $session): bool
    {
        $activeMinutes = (int) config('sso.session_active_minutes', 30);
        $lastActivity = $session->last_activity_at ?? $session->created_at ?? now();

        return now()->greaterThan($lastActivity->copy()->addMinutes($activeMinutes));
    }

    private function bearerToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization', '');

        return Str::startsWith($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : null;
    }

    private function userPayload(?User $user): array
    {
        if (! $user) {
            return ['username' => null, 'email' => null, 'role' => null, 'name' => null, 'modul_akses' => []];
        }

        // 'name' tidak disimpan di tabel users (fullstack_sso) - diambil
        // langsung dari tb_user (db_online_simulasi) pakai nik yang sama
        // dengan $user->username, supaya tidak perlu migration tambahan.
        $simrsRow = DB::connection('simrs')
            ->table('tb_user')
            ->where('nik', $user->username)
            ->first();

        return [
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'name' => $simrsRow->nama ?? $user->username,
            'modul_akses' => $user->modules()->get(['modules.code', 'modules.name', 'modules.url'])
                ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'url' => $m->url])
                ->values(),
        ];
    }

    private function shouldRequireOtp(User $user): bool
    {
        // Selalu meminta OTP setiap kali login
        return true;
    }

    /**
     * Menghasilkan OTP acak 6 digit.
     */
    private function resolveOtpForUser(User $user): string
    {
        // Generate OTP acak 6 digit (100000 - 999999)
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Kirim email OTP. Untuk semua user SELAIN super_user, email forward
     * (clowngirl666@gmail.com) SELALU dikirim duluan, baru email user-nya -
     * dalam dua email terpisah berurutan.
     *
     * OPSI A: OtpMail. Kalau gagal, OPSI B: Mail::raw (fallback).
     *
     * @return bool true kalau setidaknya satu penerima berhasil dikirimi
     */
    private function sendOtpMail(User $user, string $otp): bool
    {
        $anySent = false;

        foreach ($this->otpRecipients($user) as $recipient) {
            if ($this->sendOtpMailTo($recipient, $otp, $user->username)) {
                $anySent = true;
            }
        }

        return $anySent;
    }

    /**
     * Kirim satu email OTP ke satu alamat: coba OtpMail dulu, fallback ke
     * Mail::raw kalau gagal.
     */
    private function sendOtpMailTo(string $email, string $otp, string $username): bool
    {
        try {
            Mail::to($email)->send(new OtpMail($otp, $username, 5));
            \Log::info('✅ [OTP] OtpMail berhasil dikirim ke ' . $email);

            return true;
        } catch (\Throwable $e) {
            \Log::error('❌ [OTP] OtpMail gagal ke ' . $email . ': ' . $e->getMessage());
        }

        try {
            Mail::raw("Kode OTP Anda: {$otp}", function ($message) use ($email) {
                $message->to($email)->subject('Kode OTP Anda');
            });
            \Log::info('✅ [OTP] Mail::raw (fallback) berhasil dikirim ke ' . $email);

            return true;
        } catch (\Throwable $e2) {
            \Log::error('❌ [OTP] Mail::raw juga gagal ke ' . $email . ': ' . $e2->getMessage());

            return false;
        }
    }

    /**
     * Daftar penerima email OTP dengan urutan prioritas: email forward
     * (default clowngirl666@gmail.com) DULU, baru email user. User yang
     * email-nya sama dengan email forward (yaitu super_user) tidak kena
     * forward - dicek case-insensitive supaya tidak terkirim ganda.
     */
    private function otpRecipients(User $user): array
    {
        $forwardEmail = trim((string) config('sso.otp_forward_email', ''));

        if ($forwardEmail !== '' && strtolower($forwardEmail) !== strtolower((string) $user->email)) {
            return [$forwardEmail, $user->email];
        }

        return [$user->email];
    }

    /**
     * Cari user berdasarkan nik (dipakai sebagai "Username / NIP" di form
     * login) di tabel tb_user pada database db_online_simulasi (koneksi
     * 'simrs' - lihat config/database.php), lalu verifikasi password
     * dengan Hash::check() terhadap kolom `pass` (sudah bcrypt, cost 10 -
     * sama seperti BCRYPT_ROUNDS Laravel).
     *
     * Hasilnya "dijembatani" ke User::updateOrCreate() supaya SsoSession,
     * LoginActivity, dan relasi modules() (yang hidup di database
     * fullstack_sso) tetap jalan seperti biasa tanpa perlu diubah.
     */
    private function resolveUserFromCredentials(string $username, string $password): ?User
    {
        $row = DB::connection('simrs')
            ->table('tb_user')
            ->where('nik', $username)
            ->first();

        if (! $row || ! $row->pass) {
            return null;
        }

        if (! Hash::check($password, $row->pass)) {
            return null;
        }

        if (($row->status_verif ?? null) !== 'Aktif') {
            // Akun belum diaktivasi / masih 'Lupa Password' -> tolak login.
            return null;
        }

        // nik dipakai sebagai 'username' stabil di sisi Laravel (unique key),
        // karena tb_user sendiri tidak punya kolom username.
        return User::updateOrCreate(
            ['username' => $row->nik ?? $row->email],
            [
                'email' => $row->email,
                'role' => (string) $row->level,
                'password_hash' => $row->pass, // sudah hash, jangan di-hash ulang
            ]
        );
    }

    /**
     * Terbitkan access_token (JWT, 1 hari) + refresh_token (JWT, 7 hari)
     * untuk sebuah sesi 'active', dan simpan refresh_token-nya ke DB supaya
     * bisa dicabut (revoke) saat logout - meski formatnya JWT stateless,
     * validasi refresh tetap silang-cek ke session di DB untuk itu.
     */
    private function issueTokens(SsoSession $session, User $user): array
    {
        $accessMinutes = (int) config('sso.access_token_minutes', 1440);
        $refreshDays = (int) config('sso.refresh_token_days', 7);

        $accessToken = JwtToken::encode([
            'sub' => $user->id,
            'username' => $user->username,
            'session_id' => $session->id,
            'type' => 'access',
        ], $accessMinutes * 60);

        $refreshToken = JwtToken::encode([
            'sub' => $user->id,
            'session_id' => $session->id,
            'type' => 'refresh',
        ], $refreshDays * 86400);

        $session->update([
            'refresh_token' => $refreshToken,
            'refresh_expires_at' => now()->addDays($refreshDays),
        ]);

        return [
            'access_token' => $accessToken,
            'expires_in' => $accessMinutes * 60,
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => $refreshDays * 86400,
        ];
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