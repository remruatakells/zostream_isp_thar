<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerImportController;
use App\Http\Controllers\MikroTikCustomerImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active.user'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('admin')->group(function () {
        Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
        Route::resource('routers', RouterController::class)->except('show');

        Route::post('/packages/{package}/sync', [PackageController::class, 'sync'])->name('packages.sync');
        Route::resource('packages', PackageController::class)->except('show');

        Route::get('/customers/import', [CustomerImportController::class, 'create'])->name('customers.import.create');
        Route::post('/customers/import', [CustomerImportController::class, 'store'])->name('customers.import.store');
        Route::get('/customers-import-template', [CustomerImportController::class, 'template'])->name('customers.import.template');
        Route::get('/customers/import-mikrotik', [MikroTikCustomerImportController::class, 'create'])->name('customers.import-mikrotik.create');
        Route::post('/customers/import-mikrotik', [MikroTikCustomerImportController::class, 'store'])->name('customers.import-mikrotik.store');

        Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('users', UserController::class)->except('show');
    });

    Route::post('/customers/sync-all', [CustomerController::class, 'syncAll'])->name('customers.sync-all');
    Route::post('/customers/{customer}/sync', [CustomerController::class, 'sync'])->name('customers.sync');
    Route::post('/customers/{customer}/toggle', [CustomerController::class, 'toggle'])->name('customers.toggle');
    Route::resource('customers', CustomerController::class)->except('show');

    Route::post('/payments/checkout', [PaymentController::class, 'checkout'])->name('payments.checkout');
    Route::post('/payments/razorpay/complete', [PaymentController::class, 'completeRazorpay'])->name('payments.razorpay.complete');
    Route::resource('payments', PaymentController::class)->only(['index', 'store', 'destroy']);
});
