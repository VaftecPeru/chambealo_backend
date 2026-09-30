<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $subtotal = 0;

            foreach ($validated['items'] as $item) {
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
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Uno de los productos no existe o no está disponible.',
                    ], 422));
                }

                if ((int) $product->stock < (int) $item['quantity']) {
                    abort(response()->json([
                        'success' => false,
                        'message' => "Stock insuficiente para el producto {$product->name}.",
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

                $product->decrement('stock', $quantity);
            }

            // Regla actual: impuestos, envío y descuentos se calculan únicamente
            // en backend. Hasta que existan servicios de shipping/cupones, quedan en 0.
            $taxes = 0.0;
            $shippingCost = 0.0;
            $discount = 0.0;
            $totalAmount = max(0, $subtotal + $taxes + $shippingCost - $discount);

            return Order::create([
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

        $allowedStatuses = [
            Order::STATUS_CART,
            Order::STATUS_CHECKOUT,
            Order::STATUS_PAYMENT_PENDING,
        ];

        if (!in_array($order->status, $allowedStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Este pedido ya no puede ser cancelado.',
            ], 422);
        }

        DB::transaction(function () use ($order, $tenantId) {
            $lockedOrder = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === Order::STATUS_CANCELLED) {
                return;
            }

            foreach (($lockedOrder->items ?? []) as $item) {
                Product::where('product_id', $item['product_id'])
                    ->when($tenantId, function ($query) use ($tenantId) {
                        $query->where(function ($tenantQuery) use ($tenantId) {
                            $tenantQuery->where('tenant_id', $tenantId)
                                ->orWhereNull('tenant_id');
                        });
                    })
                    ->increment('stock', (int) $item['quantity']);
            }

            $lockedOrder->markAsCancelled();
        });

        return response()->json([
            'success' => true,
            'message' => 'Pedido cancelado correctamente.',
            'order' => $order->fresh(),
        ]);
    }

    public function updateStatus(Request $request, string $orderId)
    {
        $validated = $request->validate([
            'status' => 'required|in:shipped,delivered',
        ]);

        $tenantId = app('tenant_id');

        $order = Order::where('order_id', $orderId)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido no encontrado.',
            ], 404);
        }

        if ($validated['status'] === Order::STATUS_SHIPPED && $order->status !== Order::STATUS_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un pedido pagado puede marcarse como enviado.',
            ], 422);
        }

        if ($validated['status'] === Order::STATUS_DELIVERED && $order->status !== Order::STATUS_SHIPPED) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un pedido enviado puede marcarse como entregado.',
            ], 422);
        }

        $validated['status'] === Order::STATUS_SHIPPED
            ? $order->markAsShipped()
            : $order->markAsDelivered();

        return response()->json([
            'success' => true,
            'message' => 'Estado del pedido actualizado correctamente.',
            'order' => $order->fresh(),
        ]);
    }
}
