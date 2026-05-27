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
 * 
 * === NUEVAS PROPIEDADES AGREGADAS PARA FRONTEND ===
 * @property string|null $order_id Order ID for frontend tracking
 * @property string|null $currency Currency code (PEN, USD, etc)
 * @property string|null $payment_method Payment method
 * @property array|null $customer_data Customer information
 * @property array|null $payment_details Additional payment details
 * @property string|null $error_message Error message if failed
 * @property string|null $reference_code Unique reference code for customer
 * @property \Illuminate\Support\Carbon|null $paid_at Payment completion timestamp
 */
class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    /**
     * The attributes that are mass assignable.
     * === TODOS LOS CAMPOS ORIGINALES DEL BACKEND SE CONSERVAN ===
     * === SE AGREGARON LOS NUEVOS CAMPOS DEL FRONTEND ===
     */
    protected $fillable = [
        // === CAMPOS ORIGINALES DEL BACKEND (TODOS CONSERVADOS) ===
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
        
        // === NUEVOS CAMPOS AGREGADOS PARA FRONTEND (SIN MODIFICAR LOS ORIGINALES) ===
        'currency',
        'customer_data',
        'payment_details',
        'reference_code',
        'paid_at'
    ];

    /**
     * The attributes that should be cast.
     * === TODOS LOS CASTS ORIGINALES SE CONSERVAN ===
     * === SE AGREGARON LOS NUEVOS CASTS ===
     */
    protected $casts = [
        // === CASTS ORIGINALES DEL BACKEND ===
        'raw_data' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        
        // === NUEVOS CASTS AGREGADOS PARA FRONTEND ===
        'customer_data' => 'array',
        'payment_details' => 'array',
        'amount' => 'decimal:2',  // Mezcla: mantiene float pero especifica decimal para frontend
        'paid_at' => 'datetime'
    ];

    // ============================================================
    // === RELACIONES ORIGINALES DEL BACKEND (TODAS CONSERVADAS) ===
    // ============================================================
    
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

    // ============================================================
    // === SCOPES ORIGINALES DEL BACKEND (TODOS CONSERVADOS) ===
    // ============================================================
    
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

    // ============================================================
    // === NUEVOS MÉTODOS AGREGADOS PARA FRONTEND ===
    // ============================================================
    
    /**
     * Mark transaction as completed (frontend connection)
     */
    public function markAsCompleted(array $paymentDetails = []): void
    {
        $this->update([
            'status' => 'completed',
            'payment_details' => $paymentDetails,
            'paid_at' => now()
        ]);
    }

    /**
     * Mark transaction as failed (frontend connection)
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Generate unique reference code for customer (frontend connection)
     */
    public static function generateReferenceCode(): string
    {
        return 'REF-' . strtoupper(uniqid()) . '-' . rand(1000, 9999);
    }
}