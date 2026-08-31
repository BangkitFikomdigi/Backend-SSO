-- Fix: Correct the user records to use proper NIK/username from SIMRS
-- First, get the hash for '12#56*DS' using Laravel: Hash::make('12#56*DS')
-- All hashes computed with bcrypt cost 10

UPDATE users SET username = '3321087007040001', email = 'rchldrgn@gmail.com', role = 'admin_simrs' WHERE username = 'admin_simrs';
UPDATE users SET username = '3516014205420002', email = 'diyanashulha@gmail.com', role = 'dokter_amino' WHERE username = 'dokter_amino';
UPDATE users SET username = '3212082605810003', email = 'kawaiicompiler@gmail.com', role = 'petugas_lapor' WHERE username = 'petugas_lapor';
UPDATE users SET username = '3316080609680004', email = 'karinnyxx21@gmail.com', role = 'manager_wbs' WHERE username = 'manager_wbs';
UPDATE users SET username = '3205015307730005', email = 'clowngirl666@gmail.com', role = 'super_user' WHERE username = 'super_user';

-- Verify
SELECT id, username, email, role FROM users ORDER BY username;
