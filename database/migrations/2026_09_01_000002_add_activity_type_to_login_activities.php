<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom baru ke login_activities untuk mencatat jenis aktivitas yang lebih spesifik
        // (login, logout, password_changed, dll)
        Schema::table('login_activities', function (Blueprint $table) {
            $table->string('activity_type')->default('login')->after('reason'); // 'login', 'logout', 'password_changed', 'otp_verified', dll
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });
    }
};
