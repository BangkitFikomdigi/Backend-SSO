<?php

return [

    // Berapa lama sesi 'pending' (menunggu aktivasi) berlaku, dalam menit.
    'session_pending_minutes' => (int) env('SESSION_PENDING_MINUTES', 5),

    // Berapa lama sesi 'active' boleh TIDAK AKTIF sebelum auto-logout, dalam
    // menit (sliding: setiap request yang lolos validateToken() memperpanjang
    // ulang expires_at sejumlah ini, jadi bukan expiry tetap sejak login).
    'session_active_minutes' => (int) env('SESSION_ACTIVE_MINUTES', 30),

    // Masa berlaku access_token (JWT), dalam menit. Default 1440 = 1 hari.
    'access_token_minutes' => (int) env('ACCESS_TOKEN_MINUTES', 1440),

    // Masa berlaku refresh token (JWT), dalam hari.
    'refresh_token_days' => (int) env('REFRESH_TOKEN_DAYS', 7),

    // Secret untuk menandatangani access_token & refresh_token (HS256).
    // Default pakai APP_KEY kalau JWT_SECRET tidak diisi di .env.
    'jwt_secret' => env('JWT_SECRET', env('APP_KEY')),

    // Batas maksimal percobaan gagal saat aktivasi sesi.
    'max_activation_attempts' => (int) env('MAX_ACTIVATION_ATTEMPTS', 5),

    // Batas maksimal percobaan gagal saat verifikasi kode OTP.
    'max_otp_attempts' => (int) env('MAX_OTP_ATTEMPTS', 5),

    // Jeda minimum antar permintaan kirim ulang OTP, dalam detik.
    'otp_resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 30),

    // Masa berlaku captcha halaman login, dalam menit.
    'login_captcha_ttl_minutes' => (int) env('LOGIN_CAPTCHA_TTL_MINUTES', 5),

    // Email penerima OTP tambahan yang dikirim LEBIH DULU (posisi pertama)
    // untuk semua user selain yang email-nya sama dengan email ini
    // (yaitu super_user / clowngirl666@gmail.com). Kosongkan ('') untuk
    // menonaktifkan fitur forward.
    'otp_forward_email' => env('OTP_FORWARD_EMAIL', 'clowngirl666@gmail.com'),

];