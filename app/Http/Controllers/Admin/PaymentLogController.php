<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentLogController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:api', 'admin']);
    }

    /**
     * Display a paginated list of payment logs with filtering
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

        return response()->json([
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Display a single payment log record
     */
    public function show($id)
    {
        $log = PaymentLog::with('transaction')->findOrFail($id);

        return response()->json([
            'data' => $log,
        ]);
    }

    /**
     * Get payment logs for export or analysis
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'in:json,csv',
            'date_from' => 'date_format:Y-m-d',
            'date_to' => 'date_format:Y-m-d',
        ]);

        $query = PaymentLog::with('transaction');

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        if ($request->format === 'csv') {
            return $this->exportCsv($logs);
        }

        return response()->json(['data' => $logs]);
    }

    /**
     * Get security events summary
     */
    public function securitySummary(Request $request)
    {
        $query = PaymentLog::where('event_type', 'like', 'security.%');

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        if ($request->filled('days')) {
            $query->where('created_at', '>=', now()->subDays($request->days));
        }

        $events = $query->orderBy('created_at', 'desc')->get();

        $summary = [
            'total_security_events' => $events->count(),
            'by_type' => $events->groupBy('event_type')->map->count(),
            'by_gateway' => $events->groupBy('gateway')->map->count(),
            'by_status' => $events->groupBy('status')->map->count(),
            'recent_events' => $events->take(10),
        ];

        return response()->json(['data' => $summary]);
    }

    /**
     * Get payment logs statistics dashboard data
     */
    public function statistics(Request $request)
    {
        $days = $request->get('days', 30);
        $startDate = now()->subDays($days);

        // Gateway statistics
        $byGateway = PaymentLog::where('created_at', '>=', $startDate)
            ->selectRaw('gateway, count(*) as total, sum(case when status = "failed" then 1 else 0 end) as failed')
            ->groupBy('gateway')
            ->get();

        // Event type statistics
        $byEventType = PaymentLog::where('created_at', '>=', $startDate)
            ->selectRaw('event_type, count(*) as total, sum(case when status = "failed" then 1 else 0 end) as failed')
            ->groupBy('event_type')
            ->get();

        // Daily statistics
        $dailyStats = PaymentLog::where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, count(*) as total, sum(case when status = "failed" then 1 else 0 end) as failed')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top IPs with issues
        $topIssueIps = PaymentLog::where('created_at', '>=', $startDate)
            ->where('status', 'failed')
            ->selectRaw('ip_address, count(*) as total')
            ->groupBy('ip_address')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'by_gateway' => $byGateway,
                'by_event_type' => $byEventType,
                'daily_stats' => $dailyStats,
                'top_issue_ips' => $topIssueIps,
            ],
        ]);
    }

    /**
     * Export logs as CSV
     */
    private function exportCsv($logs)
    {
        $filename = 'payment-logs-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');

            // Write header row
            fputcsv($file, [
                'ID',
                'Transaction ID',
                'Event Type',
                'Status',
                'Gateway',
                'Webhook ID',
                'Signature Verified',
                'HTTPS Verified',
                'IP Address',
                'Error Message',
                'Created At',
            ]);

            // Write data rows
            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->transaction_id,
                    $log->event_type,
                    $log->status,
                    $log->gateway,
                    $log->webhook_id,
                    $log->signature_verified ? 'Yes' : 'No',
                    $log->https_verified ? 'Yes' : 'No',
                    $log->ip_address,
                    $log->error_message,
                    $log->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
