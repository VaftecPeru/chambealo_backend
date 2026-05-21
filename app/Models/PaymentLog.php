<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentLog Model
 * Stores detailed logs for payment transactions and webhook events
 * 
 * @property int $id
 * @property int|null $transaction_id Foreign key to transactions
 * @property string $event_type Event classification (webhook.received, webhook.processed, etc)
 * @property string $status Log status (success, failed, pending, processing, retry)
 * @property string|null $gateway Payment gateway (paypal, izipay)
 * @property string|null $webhook_id Unique webhook identifier for deduplication
 * @property array|null $request_payload Original request data
 * @property array|null $response_payload Provider response data
 * @property array|null $headers HTTP headers
 * @property string|null $error_message Error details if failed
 * @property string|null $ip_address Client IP address
 * @property string|null $user_agent Client user agent
 * @property int $attempt Retry attempt number
 * @property bool|null $signature_verified
 * @property string|null $signature_method
 * @property string|null $signature_details
 * @property bool|null $timestamp_validated
 * @property string|null $replay_prevention_id
 * @property bool|null $https_verified
 * @property string|null $tls_version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PaymentLog extends Model
{
    use HasFactory;

    protected $table = 'payment_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'transaction_id',
        'event_type',
        'status',
        'gateway',
        'webhook_id',
        'request_payload',
        'response_payload',
        'headers',
        'error_message',
        'ip_address',
        'user_agent',
        'attempt',
        'signature_verified',
        'signature_method',
        'signature_details',
        'timestamp_validated',
        'replay_prevention_id',
        'https_verified',
        'tls_version',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'headers' => 'array',
        'attempt' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'signature_verified' => 'boolean',
        'timestamp_validated' => 'boolean',
        'https_verified' => 'boolean',
    ];

    /**
     * Get the transaction associated with this log.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    /**
     * Scope to filter by event type.
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope to filter by gateway.
     */
    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by webhook ID.
     */
    public function scopeByWebhookId($query, string $webhookId)
    {
        return $query->where('webhook_id', $webhookId);
    }

    /**
     * Scope to get recent logs.
     */
    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope to get logs by status success.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get logs by status failed.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
