<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class PaymentLogViewController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'admin']);
    }

    /**
     * Display a paginated list of payment logs with filtering (Blade View)
     */
    public function index(Request $request)
    {
        $query = PaymentLog::with('transaction')->orderBy('created_at', 'desc');

        // Filter by gateway
        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        // Filter by event type
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search by webhook_id or transaction_id
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('webhook_id', 'like', '%' . $search . '%')
                  ->orWhere('id', $search)
                  ->orWhere('transaction_id', $search);
            });
        }

        // Paginate results
        $logs = $query->paginate($request->get('per_page', 50));

        // Calculate statistics
        $stats = [
            'total_today' => PaymentLog::whereDate('created_at', today())->count(),
            'failed_today' => PaymentLog::whereDate('created_at', today())->where('status', 'failed')->count(),
            'security_events' => PaymentLog::whereDate('created_at', today())->where('event_type', 'like', 'security.%')->count(),
            'by_gateway' => PaymentLog::selectRaw('gateway, count(*) as total')->groupBy('gateway')->get(),
        ];

        return view('admin.payment-logs.index', compact('logs', 'stats'));
    }

    /**
     * Display a single payment log record (Blade View)
     */
    public function show($id)
    {
        $log = PaymentLog::with('transaction')->findOrFail($id);

        return view('admin.payment-logs.show', compact('log'));
    }
}
