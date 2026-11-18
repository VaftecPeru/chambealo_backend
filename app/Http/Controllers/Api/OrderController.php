<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        $tenantId = app('tenant_id');

        $orders = Order::where('user_id', $user->user_id)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $orderId)
    {
        $user = $request->user('api') ?? $request->user();
        $tenantId = app('tenant_id');

        $order = Order::where('order_id', $orderId)
            ->where('user_id', $user->user_id)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order' => $order,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'shipping_address' => 'required|array',
            'billing_address' => 'required|array',
            'coupon_code' => 'nullable|string|max:255',
        ]);

        $user = $request->user('api') ?? $request->user();
        $tenantId = app('tenant_id');

        $order = DB::transaction(function () use ($validated, $user, $tenantId) {
            $orderItems = [];
            $reservedMovements = [];
            $subtotal = 0;

            // Suma cantidades repetidas y ordena por product_id para bloquear
            // siempre en el mismo orden (evita bloqueos cruzados entre pedidos).
            $requestedItems = collect($validated['items'])
                ->groupBy('product_id')
                ->map(fn ($group, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => (int) $group->sum('quantity'),
                ])
                ->sortKeys()
                ->values()
                ->all();

            foreach ($requestedItems as $item) {
                $product = Product::active()
                    ->where('product_id', $item['product_id'])
                    ->when($tenantId, function ($query) use ($tenantId) {
                        $query->where(function ($tenantQuery) use ($tenantId) {
                            $tenantQuery->where('tenant_id', $tenantId)
                                ->orWhereNull('tenant_id');
                        });
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'code' => 'product_unavailable',
                        'message' => 'Uno de los productos no existe o no está disponible.',
                        'product_id' => $item['product_id'],
                    ], 422));
                }

                if ((int) $product->stock < (int) $item['quantity']) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'code' => 'insufficient_stock',
                        'message' => "Stock insuficiente para el producto {$product->name}.",
                        'product_id' => $product->product_id,
                        'requested' => (int) $item['quantity'],
                        'available' => (int) $product->stock,
                    ], 422));
                }

                $price = (float) $product->price;
                $quantity = (int) $item['quantity'];
                $itemSubtotal = $price * $quantity;

                $orderItems[] = [
                    'product_id' => $product->product_id,
                    'name' => $product->name,
                    'price' => $price,
                    'quantity' => $quantity,
                    'subtotal' => $itemSubtotal,
                ];

                $subtotal += $itemSubtotal;

                $stockBefore = (int) $product->stock;
                $product->decrement('stock', $quantity);
                $reservedMovements[] = [
                    'product_id' => $product->product_id,
                    'tenant_id' => $product->tenant_id,
                    'type' => 'out',
                    'quantity' => $quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockBefore - $quantity,
                    'reason' => 'Reserva de pedido',
                ];
            }

            // Regla actual: impuestos, envío y descuentos se calculan únicamente
            // en backend. Hasta que existan servicios de shipping/cupones, quedan en 0.
            $taxes = 0.0;
            $shippingCost = 0.0;
            $discount = 0.0;
            $totalAmount = max(0, $subtotal + $taxes + $shippingCost - $discount);

            $order = Order::create([
                'order_id' => 'ORD-' . strtoupper(Str::uuid()->toString()),
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'total_amount' => $totalAmount,
                'taxes' => $taxes,
                'shipping_cost' => $shippingCost,
                'status' => Order::STATUS_CHECKOUT,
                'items' => $orderItems,
                'shipping_address' => $validated['shipping_address'],
                'billing_address' => $validated['billing_address'],
                'coupon_code' => $validated['coupon_code'] ?? null,
                'discount' => $discount,
            ]);

            foreach ($reservedMovements as $movement) {
                InventoryMovement::create($movement + [
                    'performed_by' => $user->user_id,
                    'reference_type' => 'order',
                    'reference_id' => $order->order_id,
                ]);
            }

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Pedido creado correctamente.',
            'order' => $order,
        ], 201);
    }

    public function cancel(Request $request, string $orderId)
    {
        $user = $request->user('api') ?? $request->user();
        $tenantId = app('tenant_id');

        return DB::transaction(function () use ($orderId, $user, $tenantId) {
            // Bloquear primero y validar el estado real dentro de la transacción.
            $order = Order::where('order_id', $orderId)
                ->where('user_id', $user->user_id)
                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado.',
                ], 404);
            }

            if (!$order->canTransitionTo(Order::STATUS_CANCELLED)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este pedido ya no puede ser cancelado.',
                ], 422);
            }

            // No liberar inventario si el pago está activo o en revisión.
            // El resultado externo debe aclararse antes de cancelar.
            if ($order->payments()->whereIn('status', [
                    'creating', 'creation_uncertain', 'pending', 'approved',
                ])->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El pedido tiene un pago pendiente o en revisión.',
                ], 409);
            }

            foreach (($order->items ?? []) as $item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $productId = $item['product_id'] ?? null;

                if ($quantity < 1 || !$productId) {
                    throw new \RuntimeException('Detalle de inventario del pedido inválido.');
                }

                $product = Product::where('product_id', $productId)
                    ->when($tenantId, function ($query) use ($tenantId) {
                        $query->where(function ($tenantQuery) use ($tenantId) {
                            $tenantQuery->where('tenant_id', $tenantId)
                                ->orWhereNull('tenant_id');
                        });
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw new \RuntimeException('No se pudo restaurar el stock del pedido.');
                }

                $stockBefore = (int) $product->stock;
                if ($stockBefore + $quantity > 2147483647) {
                    throw new \RuntimeException('El stock excede el limite permitido.');
                }
                $product->increment('stock', $quantity);

                InventoryMovement::create([
                    'product_id' => $product->product_id,
                    'performed_by' => $user->user_id,
                    'tenant_id' => $product->tenant_id,
                    'type' => 'in',
                    'quantity' => $quantity,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockBefore + $quantity,
                    'reason' => 'Cancelacion de pedido',
                    'reference_type' => 'order',
                    'reference_id' => $order->order_id,
                ]);
            }

            $order->markAsCancelled();

            return response()->json([
                'success' => true,
                'message' => 'Pedido cancelado correctamente.',
                'order' => $order->fresh(),
            ]);
        }, 3);
    }

    public function updateStatus(Request $request, string $orderId)
    {
        $validated = $request->validate([
            'status' => 'required|in:shipped,delivered',
        ]);

        $tenantId = app('tenant_id');

        return DB::transaction(function () use ($orderId, $validated, $tenantId) {
            // Bloquear y verificar la transición dentro de la misma transacción.
            $order = Order::where('order_id', $orderId)
                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado.',
                ], 404);
            }

            if (!$order->canTransitionTo($validated['status'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transición de estado no permitida.',
                ], 422);
            }

            $order->update(['status' => $validated['status']]);

            return response()->json([
                'success' => true,
                'message' => 'Estado del pedido actualizado correctamente.',
                'order' => $order->fresh(),
            ]);
        }, 3);
    }
}
