<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaction Model
 * Stores detailed transaction logs for audit trail and payment verification
 * 
 * @property int $id
 * @property string $transaction_id Unique transaction ID
 * @property string $order_id Reference to order
 * @property int $user_id User who made the payment
 * @property string $tenant_id Multi-tenant identifier
 * @property string $payment_method Payment provider (izipay, paypal, mercadopago)
 * @property string $process Payment process (create_session, confirm_payment, webhook)
 * @property string $status Transaction status (success, failed, pending)
 * @property float $amount Payment amount
 * @property array $request_payload Original request data
 * @property array $response_payload Provider response data
 * @property string $provider_transaction_id External provider transaction ID
 * @property string $webhook_event Webhook event type
 * @property string $ip_address Client IP address
 * @property string $user_agent Client user agent
 * @property string|null $error_message Error details if failed
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'transaction_id',
        'order_id',
        'user_id',
        'tenant_id',
        'payment_method',
        'process',
        'status',
        'amount',
        'request_payload',
        'response_payload',
        'provider_transaction_id',
        'webhook_event',
        'ip_address',
        'user_agent',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the order associated with this transaction.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the user associated with this transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the payment associated with this transaction.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'order_id', 'order_id');
    }

    /**
     * Scope to get successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get failed transactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to filter by process type.
     */
    public function scopeByProcess($query, string $process)
    {
        return $query->where('process', $process);
    }

    /**
     * Scope to filter by payment method.
     */
    public function scopeByPaymentMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }
}
