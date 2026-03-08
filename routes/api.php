<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\RestaurantBoardController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [AuthController::class,'login']);

Route::get('/health', function () {

    return response()->json([
        'success' => true,
        'message' => 'CafeOS API running'
    ]);

});

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:api')->group(function(){

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    */

    Route::get('/me', function(){

        return response()->json([
            'success' => true,
            'staff' => auth('api')->user()
        ]);

    });

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    Route::post('/orders', [OrderController::class,'store']);
    Route::get('/orders/{id}', [OrderController::class,'show']);
    Route::post('/orders/{id}/items', [OrderController::class,'addItem']);
    Route::post('/orders/{id}/send', [OrderController::class,'sendToKitchen']);

    /*
    |--------------------------------------------------------------------------
    | Kitchen
    |--------------------------------------------------------------------------
    */

    Route::get('/kitchen/queue', [KitchenController::class,'queue']);

    Route::post('/kitchen/order/{order}/item/{item}/start',
        [KitchenController::class,'startCooking']);

    Route::post('/kitchen/order/{order}/item/{item}/ready',
        [KitchenController::class,'markReady']);

    Route::post('/kitchen/order/{order}/serve',
        [KitchenController::class,'serve']);

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */

    Route::post('/orders/{id}/bill', [BillingController::class,'bill']);
    Route::post('/orders/{id}/pay', [BillingController::class,'pay']);

    /*
    |--------------------------------------------------------------------------
    | Restaurant Board
    |--------------------------------------------------------------------------
    */

    Route::get('/restaurant/board', [RestaurantBoardController::class,'index']);

});