-- Simulate forgot password flow

-- 1. Cek user
SELECT 'STEP 1: Check user' as step;
SELECT id, username, email, role FROM users WHERE username = '3321087007040001';

-- 2. Create password reset record 
SELECT 'STEP 2: Create password reset record' as step;
SET @new_id = UUID();
INSERT INTO password_resets (id, user_id, username, otp, status, attempts, expires_at, created_at)
VALUES (
  @new_id,
  (SELECT id FROM users WHERE username = '3321087007040001'),
  '3321087007040001',
  '123456',
  'pending',
  0,
  DATE_ADD(NOW(), INTERVAL 10 MINUTE),
  NOW()
);

-- 3. Cek password reset record
SELECT 'STEP 3: Password reset created' as step;
SELECT id, user_id, username, otp, status, attempts, expires_at FROM password_resets WHERE username = '3321087007040001' LIMIT 1;

-- 4. Update status menjadi verified
SELECT 'STEP 4: OTP verified' as step;
UPDATE password_resets SET status = 'verified', attempts = 0 WHERE username = '3321087007040001' AND status = 'pending' LIMIT 1;

-- 5. Current password hash
SELECT 'STEP 5: Current password before change' as step;
SELECT id, username, password_hash FROM users WHERE username = '3321087007040001';

-- 6. Cleanup test record
SELECT 'STEP 6: Cleanup' as step;
DELETE FROM password_resets WHERE username = '3321087007040001';

-- 7. Final check
SELECT 'STEP 7: Final - no more reset records' as step;
SELECT COUNT(*) as password_reset_records FROM password_resets WHERE username = '3321087007040001';
