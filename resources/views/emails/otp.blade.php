<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP</title>
    <style>
        /* Reset & base */
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f7fc;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
        }
        .container {
            max-width: 520px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            padding: 40px 35px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .header p {
            color: #64748b;
            font-size: 15px;
            margin-top: 6px;
        }
        .otp-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            border: 1px dashed #cbd5e1;
            margin: 30px 0;
            position: relative;
        }
        .otp-code {
            font-size: 48px;
            font-weight: 700;
            letter-spacing: 12px;
            color: #0f172a;
            font-family: 'Courier New', monospace;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            user-select: all;
            cursor: copy;
        }
        .otp-code:hover {
            background: #f1f5f9;
            transition: 0.2s;
        }
        .otp-label {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 8px;
            display: block;
        }
        .info {
            font-size: 14px;
            color: #334155;
            line-height: 1.6;
            margin: 20px 0 30px;
        }
        .info strong {
            color: #0f172a;
        }
        .expiry {
            background: #f1f5f9;
            padding: 10px 16px;
            border-radius: 40px;
            font-size: 13px;
            color: #475569;
            display: inline-block;
            margin-top: 10px;
        }
        .footer {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
            margin-top: 20px;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
        .btn-copy {
            background: #3b82f6;
            color: #ffffff !important;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 14px;
            display: inline-block;
            text-decoration: none;
            margin-top: 12px;
            border: none;
            cursor: pointer;
        }
        .btn-copy:hover {
            background: #2563eb;
        }
        @media (max-width: 480px) {
            .container { padding: 25px 20px; }
            .otp-code { font-size: 36px; letter-spacing: 8px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🔐 Kode OTP</h1>
        <p>Gunakan kode ini untuk melanjutkan login Anda</p>
    </div>

    <div class="otp-box">
        <span class="otp-code" id="otpValue">{{ $otp }}</span>
        <span class="otp-label">— Kode OTP —</span>
        <!-- Tombol "copy" tidak bisa berfungsi di semua email client,
             tetapi kita berikan instruksi dan style yang modern -->
        <div style="margin-top: 10px;">
            <span style="font-size:13px; color:#64748b;">👆 Klik kode di atas untuk menyalin</span>
        </div>
    </div>

    <div class="info">
        <strong>Halo, {{ $username }}</strong><br>
        Masukkan kode <strong>{{ $otp }}</strong> di halaman verifikasi.
        Kode ini hanya berlaku selama <strong>{{ $expiresIn }} menit</strong>.
    </div>

    <div style="text-align: center;">
        <span class="expiry">⏳ Berlaku hingga {{ now()->addMinutes($expiresIn)->format('H:i') }}</span>
    </div>

    <div class="footer">
        <p>© {{ date('Y') }} SSO RSJD Amino Hospital · <a href="#">Laporkan jika Anda tidak meminta ini</a></p>
    </div>
</div>

</body>
</html>