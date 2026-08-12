<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP</title>
    <style>
        /* ... semua style ... */
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Kode OTP</h1>
            <p>Gunakan kode ini untuk melanjutkan login Anda</p>
        </div>
        <div class="otp-box">
            <span class="otp-code">{{ $otp }}</span>
            <span class="otp-label">— Kode OTP —</span>
            <div style="margin-top:10px;">
                <span style="font-size:13px; color:#64748b;">👆 Klik kode di atas untuk menyalin</span>
            </div>
        </div>
        <div class="info">
            <strong>Halo, {{ $username }}</strong><br>
            Masukkan kode <strong>{{ $otp }}</strong> di halaman verifikasi.
            Kode ini berlaku selama <strong>{{ $expiresIn }} menit</strong>.
        </div>
        <div style="text-align:center;">
            <span class="expiry">⏳ Berlaku hingga {{ now()->addMinutes($expiresIn)->format('H:i') }}</span>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} SSO RSJD Amino Hospital</p>
        </div>
    </div>
</body>
</html>