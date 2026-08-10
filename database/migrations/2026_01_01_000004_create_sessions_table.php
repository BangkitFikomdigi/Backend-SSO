<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('activation_code')->nullable();
            $table->integer('activation_attempts')->default(0);
            $table->uuid('captcha_id')->nullable();
            $table->string('captcha_answer')->nullable();
            $table->string('refresh_token', 191)->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('refresh_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
