<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Listar los pedidos del usuario autenticado.
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->user_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'orders' => $orders
        ]);
    }

    /**
     * Mostrar un pedido específico del usuario autenticado.
     */
    public function show(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido no encontrado.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order' => $order
        ]);
    }

    /**
    * Crear un nuevo pedido para el usuario autenticado.
    */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',

            'shipping_address' => 'required|array',
            'billing_address' => 'required|array',

            'taxes' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:255',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $orderItems = [];
        $subtotal = 0;

        foreach ($validated['items'] as $item) {
            $product = Product::active()
            ->where('product_id', $item['product_id'])
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Uno de los productos no existe o no está disponible.'
            ], 422);
        }

        if ($product->stock < $item['quantity']) {
            return response()->json([
                'success' => false,
                'message' => "Stock insuficiente para el producto {$product->name}."
            ], 422);
        }

        $price = (float) $product->price;
        $itemSubtotal = $price * $item['quantity'];

        $orderItems[] = [
            'product_id' => $product->product_id,
            'name' => $product->name,
            'price' => $price,
            'quantity' => $item['quantity'],
            'subtotal' => $itemSubtotal,
        ];

        $subtotal += $itemSubtotal;
        }

        $taxes = (float) ($validated['taxes'] ?? 0);
        $shippingCost = (float) ($validated['shipping_cost'] ?? 0);
        $discount = (float) ($validated['discount'] ?? 0);

        $totalAmount = max(
            0,
            $subtotal + $taxes + $shippingCost - $discount
            );

        $order = Order::create([
            'order_id' => 'ORD-' . strtoupper(uniqid()),
            'user_id' => $request->user()->user_id,
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

        return response()->json([
            'success' => true,
            'message' => 'Pedido creado correctamente.',
            'order' => $order
        ], 201);
    }

    /**
     * Cancelar un pedido del usuario autenticado.
     */
    public function cancel(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido no encontrado.'
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
                'message' => 'Este pedido ya no puede ser cancelado.'
            ], 422);
        }

        $order->markAsCancelled();

        return response()->json([
            'success' => true,
            'message' => 'Pedido cancelado correctamente.',
            'order' => $order->fresh()
        ]);
    }

    /**
     * Actualizar el estado logístico de un pedido.
     * Disponible para vendor y admin mediante middleware.
     */
    public function updateStatus(Request $request, string $orderId)
    {
    $validated = $request->validate([
        'status' => 'required|in:shipped,delivered',
    ]);

        $order = Order::where('order_id', $orderId)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido no encontrado.'
            ], 404);
        }

        if (
            $validated['status'] === Order::STATUS_SHIPPED &&
            $order->status !== Order::STATUS_PAID
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un pedido pagado puede marcarse como enviado.'
            ], 422);
        }

        if (
            $validated['status'] === Order::STATUS_DELIVERED &&
            $order->status !== Order::STATUS_SHIPPED
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un pedido enviado puede marcarse como entregado.'
            ], 422);
        }

        if ($validated['status'] === Order::STATUS_SHIPPED) {
            $order->markAsShipped();
        } else {
            $order->markAsDelivered();
        }

        return response()->json([
            'success' => true,
            'message' => 'Estado del pedido actualizado correctamente.',
            'order' => $order->fresh()
        ]);
    }

}