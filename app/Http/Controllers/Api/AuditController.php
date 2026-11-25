<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:100'],
            'performed_by' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:150'],
            'q' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;

        $query = Audit::query()
            ->with('performer:user_id,full_name,email,role')
            ->forTenant($tenantId !== null ? (int) $tenantId : null);

        if (!empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        if (isset($validated['entity_id'])) {
            $query->where('entity_id', (int) $validated['entity_id']);
        }

        if (!empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (isset($validated['performed_by'])) {
            $query->where('performed_by', (int) $validated['performed_by']);
        }

        if (!empty($validated['date_from'])) {
            $query->where('timestamp', '>=', Carbon::parse($validated['date_from'])->startOfDay());
        }

        if (!empty($validated['date_to'])) {
            $query->where('timestamp', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        $search = $validated['search'] ?? $validated['q'] ?? null;
        if (!empty($search)) {
            $query->where(function ($sub) use ($search) {
                $sub->where('description', 'like', '%' . $search . '%')
                    ->orWhere('entity_type', 'like', '%' . $search . '%')
                    ->orWhere('action', 'like', '%' . $search . '%');
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = $query->orderByDesc('timestamp')->orderByDesc('audit_id')->paginate($perPage);

        $items = collect($page->items())->map(fn (Audit $audit) => $this->formatAudit($audit))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $auditId)
    {
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;

        $audit = Audit::query()
            ->with('performer:user_id,full_name,email,role')
            ->forTenant($tenantId !== null ? (int) $tenantId : null)
            ->where('audit_id', $auditId)
            ->first();

        if (!$audit) {
            return response()->json([
                'success' => false,
                'message' => 'Registro de auditoría no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatAudit($audit),
        ]);
    }

    private function formatAudit(Audit $audit): array
    {
        return [
            'audit_id' => $audit->audit_id,
            'tenant_id' => $audit->tenant_id,
            'entity_type' => $audit->entity_type,
            'entity_id' => $audit->entity_id,
            'action' => $audit->action,
            'old_values' => $this->auditService->sanitize($audit->old_values),
            'new_values' => $this->auditService->sanitize($audit->new_values),
            'description' => $audit->description,
            'performed_by' => $audit->performed_by,
            'performer' => $audit->performer ? [
                'user_id' => $audit->performer->user_id,
                'full_name' => $audit->performer->full_name,
                'email' => $audit->performer->email,
                'role' => $audit->performer->role,
            ] : null,
            'ip_address' => $audit->ip_address,
            'user_agent' => $audit->user_agent,
            'timestamp' => $audit->timestamp?->toIso8601String(),
        ];
    }
}
