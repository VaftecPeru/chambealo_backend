<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;

/**
 * PaymentRepository
 * Centralized data access layer for payment operations
 */
class PaymentRepository
{
    /**
     * Create a new order
     */
    public function createOrder(array $data): Order
    {
        return Order::create($data);
    }

    /**
     * Get order by ID
     */
    public function getOrderById(string $orderId): ?Order
    {
        return Order::where('order_id', $orderId)->first();
    }

    /**
     * Get order by internal ID
     */
    public function getOrderByPrimaryId(int $id): ?Order
    {
        return Order::find($id);
    }

    /**
     * Update order status
     */
    public function updateOrderStatus(string $orderId, string $status): bool
    {
        return (bool) Order::where('order_id', $orderId)->update(['status' => $status]);
    }

    /**
     * Get user's orders with pagination
     */
    public function getUserOrders(int $userId, int $perPage = 15): Paginator
    {
        return Order::where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get tenant's orders with pagination
     */
    public function getTenantOrders(string $tenantId, int $perPage = 15): Paginator
    {
        return Order::where('tenant_id', $tenantId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Create a payment record
     */
    public function createPayment(array $data): Payment
    {
        return Payment::create($data);
    }

    /**
     * Get payment by order ID
     */
    public function getPaymentByOrderId(string $orderId): ?Payment
    {
        return Payment::where('order_id', $orderId)->first();
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(string $orderId, string $status): bool
    {
        return (bool) Payment::where('order_id', $orderId)->update(['status' => $status]);
    }

    /**
     * Create a transaction log
     */
    public function logTransaction(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * Get transaction by ID
     */
    public function getTransactionById(int $id): ?Transaction
    {
        return Transaction::find($id);
    }

    /**
     * Get transactions by order ID
     */
    public function getTransactionsByOrderId(string $orderId): Collection
    {
        return Transaction::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all transactions for a user
     */
    public function getUserTransactions(int $userId, int $limit = 50): Collection
    {
        return Transaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed transactions in time period
     */
    public function getFailedTransactions(\DateTime $from, \DateTime $to): Collection
    {
        return Transaction::where('status', 'failed')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get successful transactions in time period
     */
    public function getSuccessfulTransactions(\DateTime $from, \DateTime $to): Collection
    {
        return Transaction::where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get transaction summary by tenant
     */
    public function getTenantTransactionSummary(string $tenantId): array
    {
        $transactions = Transaction::where('tenant_id', $tenantId)->get();

        return [
            'total_count' => $transactions->count(),
            'successful_count' => $transactions->where('status', 'success')->count(),
            'failed_count' => $transactions->where('status', 'failed')->count(),
            'total_amount' => $transactions->sum('amount'),
            'successful_amount' => $transactions->where('status', 'success')->sum('amount'),
        ];
    }

    /**
     * Check if order exists
     */
    public function orderExists(string $orderId): bool
    {
        return Order::where('order_id', $orderId)->exists();
    }

    /**
     * Check if payment exists
     */
    public function paymentExists(string $orderId): bool
    {
        return Payment::where('order_id', $orderId)->exists();
    }

    /**
     * Get orders by status
     */
    public function getOrdersByStatus(string $status, int $limit = 50): Collection
    {
        return Order::where('status', $status)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Delete old transactions (cleanup)
     */
    public function deleteOldTransactions(\DateTime $before): int
    {
        return Transaction::where('created_at', '<', $before)->delete();
    }
}
