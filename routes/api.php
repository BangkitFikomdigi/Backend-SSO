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

Route::get('/health', [AuthController::class, 'health']);

Route::prefix('auth')->group(function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/activate', [AuthController::class, 'activate']);
    Route::post('/session', [AuthController::class, 'session']);
    Route::post('/validate', [AuthController::class, 'validateToken']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::delete('/logout', [AuthController::class, 'logout']);
});

Route::prefix('api/auth')->group(function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/activate', [AuthController::class, 'activate']);
    Route::post('/session', [AuthController::class, 'session']);
    Route::post('/validate', [AuthController::class, 'validateToken']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::delete('/logout', [AuthController::class, 'logout']);
});

Route::get('/admin/login-activities', [AdminController::class, 'loginActivities']);
