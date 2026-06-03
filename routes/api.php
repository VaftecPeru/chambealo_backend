<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController as ApiPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\Admin\PaymentLogController as AdminPaymentLogController;
use App\Http\Controllers\JobController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rutas Unificadas para el Sistema de Pagos Chambealo
| - Todos los endpoints de pago usan el PaymentController unificado
| - Se mantiene compatibilidad backward con rutas v1 legacy
| - Webhooks protegidos con validación de firma
|
*/

// ============================================
// 1. AUTENTICACIÓN (SIN RATE LIMIT POR REQUEST)
// ============================================
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// ============================================
// 2. RUTAS PÚBLICAS (SIN AUTENTICACIÓN)
// ============================================
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{brand}', [BrandController::class, 'show']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    
    // Legacy endpoint - mantener para compatibilidad
    Route::post('/process-payment', [ApiPaymentController::class, 'process']);
});

// ============================================
// 3. WEBHOOKS (SIN AUTENTICACIÓN, VALIDADOS POR FIRMA)
// ============================================
// IMPORTANTE: Los webhooks NO requieren autenticación pero validan:
// - Firma del gateway (HMAC-SHA256)
// - Timestamp (ventana de 5 minutos)
// - HTTPS en producción
// - Rate limiting por IP
// - Replay attack prevention

// Nuevas rutas centralizadas de webhooks
Route::prefix('webhooks')->group(function () {
    Route::post('/{gateway}', [WebhookController::class, 'handle'])
        ->middleware('throttle:100,1')
        ->name('api.webhooks.handle');
});

// Rutas heredadas para webhooks de pago (compatibilidad)
Route::prefix('payment/webhook')->group(function () {
    Route::post('/{gateway}', [PaymentController::class, 'webhook'])
        ->middleware('throttle:20,1')
        ->name('api.payment.webhook');
});

// Legacy webhook routes para compatibilidad backward
Route::prefix('v1')->group(function () {
    Route::prefix('izipay')->group(function () {
        Route::post('/webhook', [ApiPaymentController::class, 'webhook'])
            ->middleware('throttle:60,1');
    });

    Route::prefix('paypal')->group(function () {
        Route::post('/webhook', [PayPalController::class, 'handleWebhook'])
            ->middleware('throttle:60,1');
    });

    Route::prefix('mercadopago')->group(function () {
        Route::post('/webhook', [ApiPaymentController::class, 'handleMercadoPagoWebhook'])
            ->middleware('throttle:60,1');
    });
});

// ============================================
// 4. RUTAS PROTEGIDAS (REQUIEREN AUTENTICACIÓN)
// ============================================

// Autenticación
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAllDevices']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
});

// ============================================
// 5. ENDPOINTS DE PAGO UNIFICADOS (NUEVOS)
// ============================================
// Estos son los endpoints modernos y unificados
// Reemplazan las versiones legacy

Route::middleware(['auth:sanctum', 'active'])->prefix('payment')->group(function () {
    // Crear sesión de pago
    Route::post('/session', [PaymentController::class, 'createSession'])
        ->name('api.payment.session')
        ->middleware('throttle:5,1');

    // Confirmar estado de pago
    Route::post('/confirm', [PaymentController::class, 'confirm'])
        ->name('api.payment.confirm')
        ->middleware('throttle:5,1');

    // Procesar reembolso
    Route::post('/refund', [PaymentController::class, 'refund'])
        ->name('api.payment.refund')
        ->middleware('throttle:50,1');

    // Verificar salud de gateways
    Route::get('/health', [PaymentController::class, 'healthCheck'])
        ->name('api.payment.health')
        ->middleware('throttle:60,1');
});

// ============================================
// 6. ENDPOINTS LEGACY (COMPATIBILIDAD)
// ============================================
// Se mantienen para evitar romper aplicaciones existentes
// Apuntan al PaymentController unificado cuando es posible

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('v1')->group(function () {
        // Legacy payment endpoints
        Route::post('/payment/session', [ApiPaymentController::class, 'createSession'])
            ->middleware('throttle:30,1');
        
        Route::post('/payment/confirm', [ApiPaymentController::class, 'confirm'])
            ->middleware('throttle:30,1');
        
        Route::get('/orders/{id}', [ApiPaymentController::class, 'getOrder'])
            ->middleware('throttle:60,1');
        
        Route::get('/payment/status/{order_id}', [ApiPaymentController::class, 'getPaymentStatus'])
            ->middleware('throttle:60,1');
    });
    
    // PayPal legacy routes
    Route::post('/paypal/create-order', [PayPalController::class, 'createOrder']);
    Route::post('/paypal/capture-order', [PayPalController::class, 'captureOrder']);
});

// ============================================
// 7. RUTAS DE JOBS (PROTEGIDAS)
// ============================================
Route::middleware(['auth:sanctum', 'active'])->prefix('jobs')->group(function () {
    // Conectar frontend con backend
    Route::post('/connect', [JobController::class, 'connect'])
        ->middleware('throttle:60,1')
        ->name('api.jobs.connect');
    
    // Procesar job (payment, checkout, refund, order, status)
    Route::post('/process', [JobController::class, 'process'])
        ->middleware('throttle:10,1')
        ->name('api.jobs.process');
    
    // Health check
    Route::get('/health', [JobController::class, 'health'])
        ->middleware('throttle:30,1')
        ->name('api.jobs.health');
});

// ============================================
// 8. RUTAS DE PRODUCTOS (PROTEGIDAS)
// ============================================
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/products/{product}/reviews', [ProductController::class, 'addReview']);
    Route::get('/my-products', [ProductController::class, 'myProducts']);
});

// ============================================
// 8. RUTAS DE MARCAS (PROTEGIDAS)
// ============================================
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/my-brands', [BrandController::class, 'myBrands']);
});

// ============================================
// 9. RUTAS VENDOR/ADMIN
// ============================================
Route::middleware(['auth:sanctum', 'active', 'role:vendor,admin'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
});

// ============================================
// 10. RUTAS ADMIN ONLY
// ============================================
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

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

// ============================================
// 11. RUTAS DE JOBS
// ============================================
Route::apiResource('jobs', JobController::class);
Route::post('payments', [PaymentController::class, 'store']);

// ============================================
// NOTAS IMPORTANTES:
// ============================================
// - Todos los endpoints de pago usan el PaymentController unificado
// - Los webhooks se validan por firma, sin autenticación requerida
// - Rate limiting personalizado por endpoint:
//   * createSession/confirm: 5 por minuto (prevenir fuerza bruta)
//   * refund: 50 por minuto
//   * webhook: 20 por minuto (rate limiting por IP)
//   * health: 60 por minuto
// - En producción, HTTPS es obligatorio para webhooks
// - Logging estructurado en canal 'payment'
// - Logs disponibles en storage/logs/payment.log
