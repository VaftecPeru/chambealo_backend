<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'items.*.id' => 'required',
            'items.*.name' => 'required|string',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',

            'shipping_address' => 'required|array',
            'billing_address' => 'required|array',

            'taxes' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:255',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $subtotal = collect($validated['items'])->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        $taxes = $validated['taxes'] ?? 0;
        $shippingCost = $validated['shipping_cost'] ?? 0;
        $discount = $validated['discount'] ?? 0;

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
            'items' => $validated['items'],
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
}