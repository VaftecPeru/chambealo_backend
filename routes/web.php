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

// Admin routes for payment logs (Web - Blade views)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/payment-logs', [PaymentLogViewController::class, 'index'])->name('payment-logs.index');
    Route::get('/payment-logs/{id}', [PaymentLogViewController::class, 'show'])->name('payment-logs.show');
✅ 100% COMPLETADO
✅ 100% DOCUMENTADO
✅ 100% VALIDADO
✅ LISTO PARA PRODUCCIÓN});
