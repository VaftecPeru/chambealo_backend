<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController as ApiPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\Admin\PaymentLogController as AdminPaymentLogController;
use App\Http\Controllers\JobController; // Agregado</span>

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication routes
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// Public routes
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/process-payment', [ApiPaymentController::class, 'process']);
});

// Unified Payment Webhooks (NO AUTH, WITH SIGNATURE VALIDATION)
Route::prefix('payment/webhook')->group(function () {
    Route::post('/{gateway}', [PaymentController::class, 'webhook'])
        ->middleware('throttle:20,1')
        ->name('api.payment.webhook');
});

// Legacy webhook routes (backward compatibility)
Route::prefix('v1')->group(function () {
    Route::prefix('izipay')->group(function () {
        Route::post('/create-token', [ApiPaymentController::class, 'createToken']);
        Route::post('/webhook', [ApiPaymentController::class, 'webhook'])
            ->middleware(['throttle:60,1', 'https.webhook']);
    });

    Route::prefix('paypal')->group(function () {
        Route::post('/webhook', [PayPalController::class, 'handleWebhook'])
            ->middleware(['throttle:60,1', 'https.webhook']);
    });

    Route::prefix('mercadopago')->group(function () {
        Route::post('/webhook', [ApiPaymentController::class, 'handleMercadoPagoWebhook'])
            ->middleware(['throttle:60,1', 'https.webhook']);
    });
});

// Protected routes (auth required)
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAllDevices']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // Unified Payment Endpoints (NEW)
    Route::prefix('payment')->group(function () {
        Route::post('/session', [PaymentController::class, 'createSession'])
            ->name('api.payment.session');
        Route::post('/confirm', [PaymentController::class, 'confirm'])
            ->name('api.payment.confirm');
    });

    // Legacy payment endpoints
    Route::prefix('v1')->group(function () {
        Route::post('/payment/session', [ApiPaymentController::class, 'createSession'])
            ->middleware('throttle:30,1');
        
        Route::post('/payment/confirm', [ApiPaymentController::class, 'confirm'])
            ->middleware('throttle:30,1');
        
        Route::get('/orders/{id}', [ApiPaymentController::class, 'getOrder'])
            ->middleware('throttle:60,1');
        
        Route::get('/payment/status/{order_id}', [ApiPaymentController::class, 'getPaymentStatus'])
            ->middleware('throttle:60,1');
    });
    
    // PayPal routes
    Route::post('/paypal/create-order', [PayPalController::class, 'createOrder']);
    Route::post('/paypal/capture-order', [PayPalController::class, 'captureOrder']);

    // Product routes
    Route::post('/products/{product}/reviews', [ProductController::class, 'addReview']);
    Route::get('/my-products', [ProductController::class, 'myProducts']);

    // Brand routes
    Route::get('/my-brands', [BrandController::class, 'myBrands']);
});

// Vendor and admin routes
Route::middleware(['auth:sanctum', 'active', 'role:vendor,admin'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
});

// Admin only routes
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Admin brand management
    Route::get('/admin/brands', [BrandController::class, 'adminIndex']);
    Route::put('/admin/brands/{brand}/status', [BrandController::class, 'updateVisibility']);
    
    // Admin payment logs
    Route::prefix('admin/payment-logs')->group(function () {
        Route::get('/', [AdminPaymentLogController::class, 'index'])
            ->name('admin.payment-logs.index');
        Route::get('/{id}', [AdminPaymentLogController::class, 'show'])
            ->name('admin.payment-logs.show');
        Route::get('/export/logs', [AdminPaymentLogController::class, 'export'])
            ->name('admin.payment-logs.export');
        Route::get('/security/summary', [AdminPaymentLogController::class, 'securitySummary'])
            ->name('admin.payment-logs.security');
        Route::get('/stats/dashboard', [AdminPaymentLogController::class, 'statistics'])
            ->name('admin.payment-logs.statistics');
    });
});

Route::apiResource('jobs', JobController::class);
Route::post('payments', [PaymentController::class, 'store']);

?>
