<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Endpoint-endpoint ini sengaja dibuat sama persis (path & payload) dengan
| backend Node.js/Express sebelumnya, supaya frontend PHP (Frontend/*.php)
| tidak perlu diubah sama sekali - cukup arahkan $apiBase ke server Laravel.
|
*/

// ===== Rute untuk pengecekan kesehatan server =====
Route::get('/health', [AuthController::class, 'health']);

// ===== Rute autentikasi dengan prefix /api/auth (digunakan oleh frontend) =====
Route::prefix('api/auth')->group(function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']); // <-- TAMBAHKAN UNTUK RESEND OTP
    Route::post('/activate', [AuthController::class, 'activate']);
    Route::post('/session', [AuthController::class, 'session']);
    Route::post('/validate', [AuthController::class, 'validateToken']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::delete('/logout', [AuthController::class, 'logout']);
});

// ===== Rute alternatif tanpa prefix api/auth (untuk kompatibilitas) =====
Route::prefix('auth')->group(function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/activate', [AuthController::class, 'activate']);
    Route::post('/session', [AuthController::class, 'session']);
    Route::post('/validate', [AuthController::class, 'validateToken']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::delete('/logout', [AuthController::class, 'logout']);
});

// ===== Rute login tanpa prefix (untuk kemudahan) =====
Route::post('/login', [AuthController::class, 'login']);

// ===== Rute admin (untuk melihat aktivitas login) =====
Route::get('/admin/login-activities', [AdminController::class, 'loginActivities']);