<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\PaymentLogViewController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// ============================================
// RUTAS DE PAGO (VISTAS BLADE)
// ============================================
Route::prefix('payment')->name('payment.')->group(function () {
    // Formulario de pago (requiere autenticación)
    Route::middleware('auth')->group(function () {
        Route::get('/', function () {
            return view('payments.index');
        })->name('index');

        Route::get('/refund', function () {
            return view('payments.refund');
        })->name('refund');
    });

    // Páginas de resultado (sin autenticación, pero con sesión)
    Route::get('/success', function () {
        return view('payments.success');
    })->name('success');

    Route::get('/cancel', function () {
        return view('payments.cancel');
    })->name('cancel');

    // Debug webhook (solo desarrollo)
    if (app()->environment('local', 'testing')) {
        Route::get('/webhook-debug', function () {
            return view('payments.webhook-debug');
        })->name('webhook-debug');
    }
});

// Admin routes for payment logs (Web - Blade views)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/payment-logs', [PaymentLogViewController::class, 'index'])->name('payment-logs.index');
    Route::get('/payment-logs/{id}', [PaymentLogViewController::class, 'show'])->name('payment-logs.show');
});
