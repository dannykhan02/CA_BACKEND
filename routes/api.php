<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup'])->name('signup');
    Route::post('/signin', [AuthController::class, 'signin'])->name('signin');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/signout', [AuthController::class, 'signout'])->name('signout');
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
