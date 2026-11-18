<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryMovementController extends Controller
{
    private function visibleProduct(Request $request, int $id): Product
    {
        $user = $request->user('api') ?? $request->user();
        $product = Product::findOrFail($id);
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;

        if ($tenantId !== null && $product->tenant_id !== null && (int) $product->tenant_id !== (int) $tenantId) {
            abort(404);
        }
        if (!$user->isAdmin() && !Brand::whereKey($product->brand_id)->where('user_id', $user->user_id)->exists()) {
            abort(403, 'No autorizado para gestionar inventario de este producto.');
        }
        return $product;
    }

    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;
        $query = InventoryMovement::query()->with('product:product_id,name,brand_id,tenant_id');

        if (isset($data['product_id'])) {
            $product = $this->visibleProduct($request, (int) $data['product_id']);
            $query->where('product_id', $product->product_id);
        } elseif (!$user->isAdmin()) {
            $query->whereHas('product.brand', fn ($q) => $q->where('user_id', $user->user_id));
        }

        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $page = $query->orderByDesc('movement_id')->paginate((int) ($data['per_page'] ?? 20));
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function store(Request $request, InventoryMovementService $service)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['in', 'out', 'adjust'])],
            'quantity' => ['required_unless:type,adjust', 'prohibited_if:type,adjust', 'integer', 'min:1', 'max:2147483647'],
            'target_stock' => ['required_if:type,adjust', 'prohibited_unless:type,adjust', 'integer', 'min:0', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $product = $this->visibleProduct($request, (int) $data['product_id']);
        $user = $request->user('api') ?? $request->user();
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;

        $movement = $service->applyManual(
            $product->product_id,
            (int) $user->user_id,
            $data['type'],
            isset($data['quantity']) ? (int) $data['quantity'] : null,
            isset($data['target_stock']) ? (int) $data['target_stock'] : null,
            $data['reason'],
            $tenantId !== null ? (int) $tenantId : null,
        );

        return response()->json(['success' => true, 'data' => $movement], 201);
    }
}
