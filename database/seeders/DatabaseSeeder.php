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
            ['username' => 'admin_simrs', 'password' => '12#56*DS', 'modules' => ['SIMRS']],
            ['username' => 'dokter_amino', 'password' => '11#22*AA', 'modules' => ['AMINO_MOBILE']],
            ['username' => 'petugas_lapor', 'password' => '33#44*PL', 'modules' => ['LAPOR_AMINO']],
            ['username' => 'manager_wbs', 'password' => '55#66*MW', 'modules' => ['WBS']],
            ['username' => 'super_user', 'password' => '77#88*SU', 'email' => 'girlclown666@gmail.com', 'role' => 'super_user', 'modules' => ['SIMRS', 'AMINO_MOBILE', 'LAPOR_AMINO', 'WBS']],
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
