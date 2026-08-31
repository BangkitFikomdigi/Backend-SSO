# FORGOT PASSWORD FLOW - DOKUMENTASI LENGKAP

## ENDPOINTS

### 1. Kirim OTP Reset Password
**POST** `/auth/forgot-password`

**Request:**
```json
{
  "username": "3321087007040001"  // NIK/username
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Kode OTP telah dikirim ke email Anda. Silakan cek email untuk melanjutkan reset password.",
  "data": {
    "reset_id": "01a03c2c-6g0f-739f-987d-fffffffffffg",
    "otp": "123456"  // Hanya muncul di development/local env
  }
}
```

**Error Response (400):**
```json
{
  "success": false,
  "message": "Jika akun Anda terdaftar, kami akan mengirim instruksi reset password ke email Anda."
  // Pesan yang sama untuk user exist dan tidak exist (security: info disclosure prevention)
}
```

---

### 2. Verifikasi OTP
**POST** `/auth/verify-password-reset-otp`

**Request:**
```json
{
  "reset_id": "01a03c2c-6g0f-739f-987d-fffffffffffg",
  "username": "3321087007040001",
  "otp": "123456"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "OTP terverifikasi. Silakan masukkan password baru.",
  "data": {
    "reset_id": "01a03c2c-6g0f-739f-987d-fffffffffffg",
    "username": "3321087007040001"
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "OTP tidak sesuai",
  "remaining_attempts": 3
}
```

**Batasan:**
- Max 5 percobaan salah
- OTP berlaku 10 menit
- Jika OTP salah lebih dari 5x, request dikunci (status = 'locked')

---

### 3. Set Password Baru
**POST** `/auth/set-new-password`

**Request:**
```json
{
  "reset_id": "01a03c2c-6g0f-739f-987d-fffffffffffg",
  "username": "3321087007040001",
  "password": "PasswordBaru123!"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Password berhasil diubah. Silakan login dengan password baru Anda.",
  "data": {
    "redirect_to": "/login"
  }
}
```

**Validasi:**
- Password minimal 8 karakter
- reset_id dan username harus cocok
- Status reset request harus 'verified'

---

## DATABASE CHANGES

### Database yang Diupdate: KEDUA DATABASE

#### 1. **fullstack_sso** (SSO Database - Local)

**Table: password_resets**
```
- id (UUID, Primary Key)
- user_id (Foreign Key ke users.id)
- username (NIK/username)
- otp (6-digit code)
- status (pending / verified / completed / expired / locked)
- attempts (counter untuk OTP salah)
- expires_at (timestamp)
- created_at (timestamp)
```

**Table: login_activities**
- Ditambah kolom baru: `activity_type` (varchar, default 'login')
- Mencatat aktivitas: login, logout, **password_changed**, forgot_password_otp_sent, password_reset_otp_verified, dll

**Update di users table:**
- `password_hash` diupdate dengan hash password baru (bcrypt, cost 10)

#### 2. **db_online_simulasi** (SIMRS Database - Remote)

**Table: tb_user**
- `pass` diupdate dengan hash password baru
- Jika gagal update di SIMRS (network/timeout), tetap proceed dengan warning log

---

## FLOW DAN KEAMANAN

### Step-by-Step Flow:

1. **User input username** → Cari di SIMRS tb_user
2. **OTP digenerate** → Simpan di `password_resets` + cache (10 menit TTL)
3. **Email dikirim** → Gunakan Mail::raw via SMTP (Gmail)
4. **User input OTP** → Verify melawan cache/database, counter attempts
5. **OTP valid** → Update status menjadi 'verified' di password_resets
6. **User input password baru** → Update password di KEDUA database
7. **Selesai** → Logout semua session user (force re-login), redirect ke /login

### Keamanan:

✅ **Brute Force Protection:**
- Max 5 percobaan OTP salah per request
- Request dikunci setelah 5x gagal (status='locked')
- OTP expiry 10 menit

✅ **Information Disclosure Prevention:**
- Same response message apakah user exist atau tidak

✅ **Session Revocation:**
- Setelah password diubah, semua session user dihapus
- User dipaksa logout dan harus login ulang

✅ **Dual Database Update:**
- Update di SSO local + SIMRS remote
- Fallback jika SIMRS gagal (tetap update SSO)

✅ **Audit Trail:**
- Setiap aktivitas dicatat di login_activities dengan activity_type:
  - `forgot_password_otp_sent`
  - `password_reset_otp_verified`
  - `password_changed`

---

## TESTING CHECKLIST

- [ ] User bisa request OTP dengan username yang terdaftar
- [ ] Email OTP diterima (check Gmail)
- [ ] OTP valid diterima dengan benar
- [ ] OTP yang salah > 5x → request dikunci
- [ ] Password baru berhasil disimpan di `fullstack_sso.users.password_hash`
- [ ] Password baru juga tersimpan di `db_online_simulasi.tb_user.pass`
- [ ] login_activities tercatat dengan activity_type yang benar
- [ ] Redirect ke /login setelah password berhasil diubah
- [ ] User bisa login dengan password baru
- [ ] Session lama user sudah invalid (logout force)

---

## MONITORING QUERIES

Cek password reset records:
```sql
SELECT * FROM password_resets ORDER BY created_at DESC;
```

Cek aktivitas password change:
```sql
SELECT * FROM login_activities 
WHERE activity_type IN ('password_changed', 'forgot_password_otp_sent', 'password_reset_otp_verified')
ORDER BY created_at DESC;
```

Cek password sudah berubah:
```sql
-- SSO Database
SELECT id, username, password_hash FROM users WHERE username = '3321087007040001';

-- SIMRS Database
SELECT nik, pass FROM tb_user WHERE nik = '3321087007040001';
```

---

## TROUBLESHOOTING

**Problem: Email OTP tidak diterima**
- Check `.env`: MAIL_MAILER, MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD
- Check Laravel logs: `storage/logs/laravel.log`
- Verify Gmail app password (bukan password biasa)

**Problem: OTP request error 404**
- Verify routes sudah terdaftar: `php artisan route:list | grep forgot`

**Problem: Password tidak berubah di SIMRS**
- Check `db_online_simulasi` connection di `config/database.php`
- Verify credentials (DB_SIMRS_USERNAME, DB_SIMRS_PASSWORD)
- Check firewall/network connectivity ke 192.168.0.2:3345

**Problem: Status masih "pending" setelah verify OTP**
- Check apakah OTP match (case sensitive)
- Check waktu server dan expiry time di password_resets

