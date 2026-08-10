<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Login</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #111827;">
    <h2>OTP Login SSO</h2>
    <p>Halo <strong>{{ $username }}</strong>,</p>
    <p>Berikut kode OTP Anda untuk login:</p>
    <div style="font-size: 28px; font-weight: bold; letter-spacing: 4px; margin: 20px 0; color: #2563eb;">
        {{ $otp }}
    </div>
    <p>Kode ini berlaku selama {{ $expiresIn }} menit.</p>
    <p>Jangan bagikan kode ini kepada siapapun.</p>
</body>
</html>
