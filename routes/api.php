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
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/activate', [AuthController::class, 'activate']);
    Route::post('/session', [AuthController::class, 'session']);
    Route::post('/validate', [AuthController::class, 'validateToken']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::delete('/logout', [AuthController::class, 'logout']);
    // Forgot password flow
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-password-reset-otp', [AuthController::class, 'verifyPasswordResetOtp']);
    Route::post('/set-new-password', [AuthController::class, 'setNewPassword']);
});

Route::prefix('api/auth')->group(function () {
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
    // Forgot password flow
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-password-reset-otp', [AuthController::class, 'verifyPasswordResetOtp']);
    Route::post('/set-new-password', [AuthController::class, 'setNewPassword']);
});

Route::post('/login', [AuthController::class, 'login']);

Route::get('/admin/login-activities', [AdminController::class, 'loginActivities']);

// Handle CORS preflight OPTIONS request untuk semua route
Route::options('/{any}', function () {
    return response()->json();
})->where('any', '.*');
