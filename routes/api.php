<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 🔥 CORREGIDO: Rutas de autenticación SIN duplicados
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// Rutas públicas
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
});

// Rutas protegidas
Route::middleware(['auth:api', 'active'])->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAllDevices']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // Product routes
    Route::post('/products/{product}/reviews', [ProductController::class, 'addReview']);
    Route::get('/my-products', [ProductController::class, 'myProducts']);

    // Brand routes para usuarios autenticados
    Route::get('/my-brands', [BrandController::class, 'myBrands']);
});

// Rutas para vendors y admin
Route::middleware(['auth:api', 'active', 'role:vendor,admin'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
});

// Rutas solo para admin
Route::middleware(['auth:api', 'active', 'role:admin'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Admin brand management
    Route::get('/admin/brands', [BrandController::class, 'adminIndex']);
    Route::put('/admin/brands/{brand}/status', [BrandController::class, 'updateVisibility']);
});
