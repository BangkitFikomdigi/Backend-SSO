<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_modules', function (Blueprint $table) {
            $table->id(); // Auto-increment primary key
            $table->uuid('user_id');
            $table->uuid('module_id');

            // Unique constraint untuk prevent duplicate
            $table->unique(['user_id', 'module_id']);

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_modules');
    }
};