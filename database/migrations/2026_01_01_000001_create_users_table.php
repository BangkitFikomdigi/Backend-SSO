<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // UUID dibuat di sisi aplikasi (model User pakai trait HasUuids),
            // bukan lewat default kolom database - supaya portable di MySQL.
            $table->uuid('id')->primary();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('email')->nullable();
            $table->string('role')->default('user');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
