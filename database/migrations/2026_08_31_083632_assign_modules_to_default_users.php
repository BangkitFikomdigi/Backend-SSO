<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Assign modul ke 4 user default (selain super_user). User ini dibuat
     * otomatis saat login pertama kali via resolveUserFromCredentials(),
     * sebelum seeder sempat run, jadi modules() mereka kosong.
     */
    public function up(): void
    {
        $mapping = [
            'admin_simrs' => ['SIMRS'],
            'dokter_amino' => ['AMINO_MOBILE'],
            'petugas_lapor' => ['LAPOR_AMINO'],
            'manager_wbs' => ['WBS'],
        ];

        foreach ($mapping as $username => $moduleCodes) {
            $user = User::where('username', $username)->first();
            if (! $user) {
                continue; // Skip jika user belum ada
            }

            $moduleIds = Module::whereIn('code', $moduleCodes)->pluck('id');
            foreach ($moduleIds as $moduleId) {
                DB::table('user_modules')->updateOrInsert(
                    ['user_id' => $user->id, 'module_id' => $moduleId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $users = ['admin_simrs', 'dokter_amino', 'petugas_lapor', 'manager_wbs'];
        $userIds = User::whereIn('username', $users)->pluck('id');
        DB::table('user_modules')->whereIn('user_id', $userIds)->delete();
    }
};
