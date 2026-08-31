<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Data ini persis sama dengan yang di-seed otomatis oleh initDatabase()
     * pada versi Node.js (Backend/server.js), supaya kredensial demo tetap
     * sama untuk seluruh tim.
     * 
     * ⚠️ PENTING: username HARUS cocok dengan nik di tb_user (db_online_simulasi)!
     * Jangan pakai nama role (admin_simrs, dll) - harus pakai NIK asli dari sistem SIMRS.
     */
    public function run(): void
    {
        $modules = [
            ['code' => 'SIMRS', 'name' => 'SIMRS', 'url' => 'https://rs-amino.jatengprov.go.id/login/'],
            ['code' => 'AMINO_MOBILE', 'name' => 'AMINO Mobile', 'url' => 'https://rs-amino.jatengprov.go.id/inovasi-amino-mobile/'],
            ['code' => 'LAPOR_AMINO', 'name' => 'LAPOR AMINO', 'url' => 'https://rs-amino.jatengprov.go.id/pengaduaninformasi-pasien/'],
            ['code' => 'WBS', 'name' => 'WBS', 'url' => 'https://rs-amino.jatengprov.go.id/sistem-pelaporan-pelanggaran-wbs/'],
        ];

        foreach ($modules as $module) {
            Module::firstOrCreate(['code' => $module['code']], $module);
        }

        $defaultUsers = [
            ['username' => '3321087007040001', 'email' => 'rchldrgn@gmail.com', 'password' => '12#56*DS', 'role' => 'admin_simrs', 'modules' => ['SIMRS']],
            ['username' => '3516014205420002', 'email' => 'diyanashulha@gmail.com', 'password' => '11#22*AA', 'role' => 'dokter_amino', 'modules' => ['AMINO_MOBILE']],
            ['username' => '3212082605810003', 'email' => 'kawaiicompiler@gmail.com', 'password' => '33#44*PL', 'role' => 'petugas_lapor', 'modules' => ['LAPOR_AMINO']],
            ['username' => '3316080609680004', 'email' => 'karinnyxx21@gmail.com', 'password' => '55#66*MW', 'role' => 'manager_wbs', 'modules' => ['WBS']],
            ['username' => '3205015307730005', 'email' => 'clowngirl666@gmail.com', 'password' => '77#88*SU', 'role' => 'super_user', 'modules' => ['SIMRS', 'AMINO_MOBILE', 'LAPOR_AMINO', 'WBS']],
        ];

        foreach ($defaultUsers as $data) {
            $user = User::firstOrCreate(
                ['username' => $data['username']],
                [
                    'password_hash' => Hash::make($data['password']),
                    'email' => $data['email'] ?? null,
                    'role' => $data['role'] ?? 'user',
                ]
            );

            $moduleIds = Module::whereIn('code', $data['modules'])->pluck('id', 'code');
            foreach ($data['modules'] as $code) {
                if (isset($moduleIds[$code])) {
                    $user->modules()->syncWithoutDetaching([$moduleIds[$code]]);
                }
            }
        }

        $this->command?->info('✅ Modul & user default berhasil di-seed.');
    }
}
