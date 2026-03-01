<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Laravel API working',
        'version' => 'v1'
    ]);
});
