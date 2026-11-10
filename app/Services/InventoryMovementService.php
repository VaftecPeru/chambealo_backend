<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryMovementService
{
    public function applyManual(int $productId, int $userId, string $type, ?int $quantity, ?int $targetStock, string $reason, ?int $tenantId): InventoryMovement
    {
        return DB::transaction(function () use ($productId, $userId, $type, $quantity, $targetStock, $reason, $tenantId) {
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();

            if ($tenantId !== null && $product->tenant_id !== null && (int) $product->tenant_id !== $tenantId) {
                abort(404);
            }

            $before = (int) $product->stock;
            $after = match ($type) {
                'in' => $before + (int) $quantity,
                'out' => $before - (int) $quantity,
                'adjust' => (int) $targetStock,
                default => throw ValidationException::withMessages(['type' => 'Tipo de movimiento inválido.']),
            };

            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock insuficiente para este movimiento.']);
            }
            if ($after > 2147483647) {
                throw ValidationException::withMessages(['quantity' => 'El stock excede el límite permitido.']);
            }
            if ($after === $before) {
                throw ValidationException::withMessages(['quantity' => 'El movimiento debe modificar el stock.']);
            }

            $product->stock = $after;
            $product->save();

            return InventoryMovement::create([
                'product_id' => $product->product_id,
                'performed_by' => $userId,
                'tenant_id' => $product->tenant_id,
                'type' => $type,
                'quantity' => abs($after - $before),
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => $reason,
            ]);
        }, 3);
    }
}
