<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\PayPalController;

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

// Rutas para IziPay
Route::prefix('v1/izipay')->group(function () {
    Route::post('/create-token', [PaymentController::class, 'createToken']);
    // IziPay Webhook - External webhook, no auth required but throttled to prevent abuse
    Route::post('/webhook', [PaymentController::class, 'webhook'])
        ->middleware('throttle:60,1');
});

// VAFTEC: Webhooks PayPal - Eventos recomendados (punto 9)
Route::prefix('v1/paypal')->group(function () {
    // PayPal Webhook - External webhook, no auth required but throttled to prevent abuse
    Route::post('/webhook', [PayPalController::class, 'handleWebhook'])
        ->middleware('throttle:60,1');
});

// Rutas públicas
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/process-payment', [PaymentController::class, 'process']);
});

// Rutas protegidas
Route::middleware(['auth:api', 'active'])->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAllDevices']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
  
    // VAFTEC Payment Endpoints (v1)
    Route::prefix('v1')->group(function () {
        // Payment Session Creation - Create payment session for Izipay/PayPal
        Route::post('/payment/session', [PaymentController::class, 'createSession'])
            ->middleware('throttle:30,1');
        
        // Payment Confirmation - Confirm payment after form completion
        Route::post('/payment/confirm', [PaymentController::class, 'confirm'])
            ->middleware('throttle:30,1');
        
        // Query Endpoints - Get payment status and order details
        Route::get('/orders/{id}', [PaymentController::class, 'getOrder'])
            ->middleware('throttle:60,1');
        
        Route::get('/payment/status/{order_id}', [PaymentController::class, 'getPaymentStatus'])
            ->middleware('throttle:60,1');
    });
    
    // Rutas para Paypal - Usar el mismo guard
    Route::post('/paypal/create-order', [PayPalController::class, 'createOrder']);
    Route::post('/paypal/capture-order', [PayPalController::class, 'captureOrder']);

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
