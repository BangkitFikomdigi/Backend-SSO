<!DOCTYPE html>
<html>
<head>
    <title>Kode OTP</title>
</head>
<body>
    <h1>Halo {{ $username }}</h1>
    <p>Kode OTP Anda adalah: <strong>{{ $otp }}</strong></p>
    <p>Kode ini berlaku selama {{ $expiresIn }} menit.</p>
</body>
</html>