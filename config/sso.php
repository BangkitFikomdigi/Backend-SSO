<?php

return [

    // Berapa lama sesi 'pending' (menunggu aktivasi) berlaku, dalam menit.
    'session_pending_minutes' => (int) env('SESSION_PENDING_MINUTES', 5),

    // Berapa lama sesi 'active' berlaku sebelum harus di-refresh, dalam menit.
    'session_active_minutes' => (int) env('SESSION_ACTIVE_MINUTES', 15),

    // Masa berlaku refresh token, dalam hari.
    'refresh_token_days' => (int) env('REFRESH_TOKEN_DAYS', 7),

    // Batas maksimal percobaan gagal saat aktivasi sesi.
    'max_activation_attempts' => (int) env('MAX_ACTIVATION_ATTEMPTS', 5),

    // Masa berlaku captcha halaman login, dalam menit.
    'login_captcha_ttl_minutes' => (int) env('LOGIN_CAPTCHA_TTL_MINUTES', 5),

];
