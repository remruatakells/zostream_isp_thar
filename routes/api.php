<?php

use App\Http\Controllers\Api\V1\IspController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('/dashboard', [IspController::class, 'dashboard']);
    Route::get('/customers', [IspController::class, 'customers']);
    Route::post('/customers', [IspController::class, 'storeCustomer']);
    Route::patch('/customers/{customer}', [IspController::class, 'updateCustomer']);
    Route::post('/customers/{customer}/sync', [IspController::class, 'syncCustomer']);
    Route::post('/customers/{customer}/toggle', [IspController::class, 'toggleCustomer']);
    Route::get('/packages', [IspController::class, 'packages']);
    Route::get('/routers', [IspController::class, 'routers']);
    Route::post('/routers/{router}/test', [IspController::class, 'testRouter']);
});
