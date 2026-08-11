<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tambah kolom email (unique, nullable agar tidak error untuk data lama)
            $table->string('email')->unique()->nullable()->after('username');
            
            // Tambah kolom role (nullable)
            $table->string('role')->nullable()->after('password_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'role']);
        });
    }
};