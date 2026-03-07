<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'CafeOS API running'
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [AuthController::class,'login']);

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
*/

Route::post('/orders', [OrderController::class,'store']);
Route::post('/orders/{id}/items', [OrderController::class,'addItem']);
Route::post('/orders/{id}/send', [OrderController::class,'sendToKitchen']);