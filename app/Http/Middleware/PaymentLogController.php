<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class PaymentLogController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentLog::query()->latest();

        if ($request->has('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->paginate(20);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($logs);
        }

        return view('admin.payment-logs.index', compact('logs'));
    }

    public function show($id)
    {
        $log = PaymentLog::findOrFail($id);

        if (request()->expectsJson() || request()->is('api/*')) {
            return response()->json($log);
        }

        return view('admin.payment-logs.show', compact('log'));
    }
}
