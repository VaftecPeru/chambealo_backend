<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

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
     * Create or refresh a pending payment for an existing order.
     * Keeps the unique order_id constraint idempotent across retries.
     */

/**
 * Reserva una sesión PayPal antes de crear la orden externa.
 *
 * La restricción UNIQUE de payments.order_id impide
 * dos reservas simultáneas para el mismo pedido.
 */
public function reservePayPalSession(array $data): Payment
{
    if (($data['gateway'] ?? null) !== 'paypal') {
        throw new \InvalidArgumentException(
            'La reserva solo admite PayPal.'
        );
    }

    if (
        ($data['currency'] ?? null) !== 'USD' ||
        ($data['original_currency'] ?? null) !== 'PEN'
    ) {
        throw new \InvalidArgumentException(
            'La conversion monetaria es obligatoria.'
        );
    }

    return DB::transaction(function () use ($data) {
        $existing = Payment::where(
            'order_id',
            $data['order_id']
        )->lockForUpdate()->first();

        if ($existing) {
            throw new \RuntimeException(
                'Este pedido ya tiene un registro de pago.'
            );
        }

        return Payment::create(array_merge($data, [
            'status' => 'creating',
            'payment_id' => null,
        ]));
    }, 3);
}

/**
 * Finalizar una reserva después de crear la orden en PayPal.
 */
public function finalizePayPalSession(
    int $paymentId,
    array $result
): Payment {
    $paypalOrderId = $result['id'] ?? null;
    $approveUrl = $result['approve_url'] ?? null;

    if (!is_string($paypalOrderId) || $paypalOrderId === '' ||
        !is_string($approveUrl) || $approveUrl === '') {
        throw new \InvalidArgumentException(
            'La respuesta de PayPal no contiene una orden valida.'
        );
    }

    return DB::transaction(function () use (
        $paymentId,
        $paypalOrderId,
        $result
    ) {
        $payment = Payment::whereKey($paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $payment->gateway !== 'paypal' ||
            $payment->status !== 'creating' ||
            $payment->payment_id !== null
        ) {
            throw new \RuntimeException(
                'La reserva PayPal no puede finalizarse.'
            );
        }

        $payment->payment_id = $paypalOrderId;
        $payment->status = 'pending';
        $payment->raw_response = $result;
        $payment->save();

        return $payment;
    }, 3);
}

/**
 * Marca una reserva PayPal como incierta.
 *
 * Se utiliza cuando no sabemos si PayPal creo la orden
 * porque hubo un error de conexion o una respuesta incompleta.
 *
 * No libera la reserva ni permite otro intento automatico.
 */
public function markPayPalSessionUncertain(
    int $paymentId,
    string $reason
): Payment {
    return DB::transaction(function () use ($paymentId) {
        $payment = Payment::whereKey($paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $payment->gateway !== 'paypal' ||
            $payment->status !== 'creating' ||
            $payment->payment_id !== null
        ) {
            throw new \RuntimeException(
                'La reserva PayPal no puede marcarse para revision.'
            );
        }

        $payment->status = 'creation_uncertain';

        $payment->raw_response = [
            'error_type' => 'creation_uncertain',
            'message' => 'No se pudo confirmar la creacion de la orden PayPal.',
            'requires_reconciliation' => true,
        ];

        $payment->save();

        return $payment;
    }, 3);
}
public function upsertPaymentForOrder(array $data): Payment
{
    return DB::transaction(function () use ($data) {
        $payment = Payment::where(
            'order_id',
            $data['order_id']
        )->lockForUpdate()->first();

        if ($payment) {
            // Nunca sobrescribir un pago completado.
            if (in_array(strtolower($payment->status), [
    'creating',
    'creation_uncertain',
    'pending',
    'approved',
], true)) {
    throw new \RuntimeException(
        'Este pedido ya tiene una sesion de pago activa.'
    );
}

            // Solo reutilizar registros fallidos o cancelados.
            if (!in_array(strtolower($payment->status), [
                'failed',
                'cancelled',
            ], true)) {
                throw new \RuntimeException(
                    'El estado del pago no permite reemplazarlo.'
                );
            }

            $payment->fill($data);
            $payment->save();

            return $payment;
        }

        return Payment::create($data);
    }, 3);
}
    /**
     * Get payment by order ID
     */
    public function getPaymentByOrderId(string $orderId): ?Payment
    {
        return Payment::where('order_id', $orderId)->first();
    }

    /**
     * Get payment by external payment ID
     */
    public function getPaymentByPaymentId(string $paymentId): ?Payment
    {
        return Payment::where('payment_id', $paymentId)->first();
    }

    /**
     * Update payment status and optionally create transaction
     */

public function updatePaymentStatus(
    string $identifier,
    string $status,
    array $rawResponse = null
): Payment {
    return DB::transaction(function () use (
        $identifier,
        $status,
        $rawResponse
    ) {
        // Bloqueo de fila para serializar confirmaciones
        // del mismo pago.
        $payment = Payment::where('payment_id', $identifier)
            ->orWhere('order_id', $identifier)
            ->lockForUpdate()
            ->firstOrFail();

        $status = strtolower($status);

        // Nunca retroceder un pago ya completado.
        if (in_array(strtolower($payment->status), [
            'completed',
            'paid',
        ], true)) {
            return $payment;
        }

        // Las aprobaciones no equivalen a capturas.
        $isCompleted = in_array($status, [
            'completed',
            'paid',
        ], true);

        $transactionId = $rawResponse['transaction_id']
            ?? $rawResponse['id']
            ?? null;

        // Para PayPal exigimos el Capture ID real.
        if ($payment->gateway === 'paypal' && $isCompleted) {
            $transactionId = $rawResponse['transaction_id']
                ?? null;

            if (!is_string($transactionId)
                || $transactionId === '') {
                throw new \RuntimeException(
                    'No se recibio un Capture ID valido de PayPal.'
                );
            }
        }

        $payment->status = $status;

        if ($rawResponse !== null) {
            $payment->raw_response = $rawResponse;
        }

        $payment->save();

        if ($isCompleted && $rawResponse !== null) {
            // No crear transacciones sin identificador.
            if (!$transactionId) {
                throw new \RuntimeException(
                    'Falta el identificador de transaccion.'
                );
            }

            // Comprobar que ese identificador no pertenezca
            // a otro pago.
            $existing = Transaction::where(
                'transaction_id',
                $transactionId
            )->first();

            if ($existing) {
                if ((int) $existing->payment_id
                    !== (int) $payment->id) {
                    throw new \RuntimeException(
                        'La transaccion pertenece a otro pago.'
                    );
                }

                return $payment;
            }

            Transaction::create([
                'payment_id' => $payment->id,
                'transaction_id' => $transactionId,
                'provider_transaction_id' => $transactionId,
                'order_id' => $payment->order_id,
                'user_id' => $payment->user_id,
                'tenant_id' => $payment->tenant_id,
                'payment_method' => $payment->gateway,
                'process' => 'confirm_payment',
                'status' => 'success',
                'amount' => $payment->amount,
                'response_payload' => $rawResponse,
                'raw_data' => $rawResponse,
            ]);
        }

        return $payment;
    }, 3);
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
