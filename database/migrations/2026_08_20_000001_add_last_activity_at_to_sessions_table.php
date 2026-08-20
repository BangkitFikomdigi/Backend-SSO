<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom last_activity_at untuk melacak aktivitas terakhir user
 * pada sesi 'active', terpisah dari expires_at.
 *
 * Kolom ini dipakai untuk mendeteksi inactivity-timeout 30 menit:
 * - Setiap request yang lolos validateToken() -> last_activity_at = now()
 * - Jika now() - last_activity_at > session_active_minutes -> status jadi 'inactive'
 *   (bukan 'expired'), karena access/refresh token JWT-nya sendiri MASIH valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
