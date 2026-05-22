<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'total_logs' => PaymentLog::count(),
            'successful' => PaymentLog::where('status', 'success')->count(),
            'failed' => PaymentLog::where('status', 'failed')->count(),
            'paypal_count' => PaymentLog::where('gateway', 'paypal')->count(),
            'izipay_count' => PaymentLog::where('gateway', 'izipay')->count(),
            'mercadopago_count' => PaymentLog::where('gateway', 'mercadopago')->count(),
        ];

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($stats);
        }

        return view('admin.dashboard', compact('stats'));
    }
}
