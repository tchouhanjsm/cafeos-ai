<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success'=>true,
        'message'=>'CafeOS API running'
    ]);
});

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
*/

Route::get('/orders', function () {
    return response()->json([
        'message' => 'Orders module coming back'
    ]);
});

/*
|--------------------------------------------------------------------------
| Kitchen
|--------------------------------------------------------------------------
*/

Route::get('/kitchen/queue', function () {
    return response()->json([
        'message' => 'Kitchen module coming back'
    ]);
});

/*
|--------------------------------------------------------------------------
| Restaurant Board
|--------------------------------------------------------------------------
*/

Route::get('/restaurant/board', function () {
    return response()->json([
        'message' => 'Restaurant board module coming back'
    ]);
});