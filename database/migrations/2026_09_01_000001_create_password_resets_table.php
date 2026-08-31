<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('username'); // Username/NIK dari user yang lupa password
            $table->string('otp', 10);
            $table->string('status'); // 'pending' (OTP dikirim), 'verified' (OTP cocok, waiting untuk password baru)
            $table->integer('attempts')->default(0); // Counter percobaan OTP yang salah
            $table->timestamp('expires_at'); // OTP kadaluarsa dalam 10 menit
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('username');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
