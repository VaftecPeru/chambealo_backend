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
 * @property int $payment_id Foreign key to payments table
 * @property string $transaction_id Unique transaction ID
 * @property string $gateway Payment gateway (izipay, paypal, mercadopago)
 * @property string $status Transaction status (success, failed, pending)
 * @property float $amount Payment amount
 * @property array $raw_data Provider response data
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'payment_id',
        'transaction_id',
        'order_id',
        'user_id',
        'tenant_id',
        'gateway',
        'payment_method',
        'process',
        'status',
        'amount',
        'raw_data',
        'request_payload',
        'response_payload',
        'provider_transaction_id',
        'webhook_event',
        'ip_address',
        'user_agent',
        'error_message',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the payment associated with this transaction
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the order associated with this transaction
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the user associated with this transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Scope to get successful transactions
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get failed transactions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to filter by process type
     */
    public function scopeByProcess($query, string $process)
    {
        return $query->where('process', $process);
    }

    /**
     * Scope to filter by payment method
     */
    public function scopeByPaymentMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }
}
