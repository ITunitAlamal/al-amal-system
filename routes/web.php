<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\DepartmentController;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])
        ->name('login');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::prefix('admin/departments')
        ->name('admin.departments.')
        ->controller(DepartmentController::class)
        ->group(function () {

            Route::get('/', 'index')->name('index');

            Route::post('/', 'store')->name('store');

            Route::put('/{department}', 'update')->name('update');

            Route::patch('/{department}/toggle', 'toggle')
                ->name('toggle');
        });
});
