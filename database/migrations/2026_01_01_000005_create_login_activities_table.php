<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Indeks untuk mempercepat pencarian riwayat by username & waktu,
        // sama seperti pada versi Node.js/PostgreSQL.
        Schema::table('login_activities', function (Blueprint $table) {
            $table->index('username', 'idx_login_activities_username');
            $table->index('created_at', 'idx_login_activities_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
    }
};
