<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_CART = 'cart';
    public const STATUS_CHECKOUT = 'checkout';
    public const STATUS_PAYMENT_PENDING = 'payment_pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Transiciones válidas. No se permiten retrocesos ni saltos de etapa.
     * checkout -> paid se admite para confirmaciones de pago ya existentes.
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_CART => [self::STATUS_CHECKOUT, self::STATUS_CANCELLED],
        self::STATUS_CHECKOUT => [self::STATUS_PAYMENT_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAYMENT_PENDING => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_SHIPPED],
        self::STATUS_SHIPPED => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    public function canTransitionTo(string $nextStatus): bool
    {
        return in_array(
            $nextStatus,
            self::ALLOWED_TRANSITIONS[$this->status] ?? [],
            true
        );
    }

    protected $fillable = [
        'order_id',
        'tenant_id',
        'user_id',
        'total_amount',
        'taxes',
        'shipping_cost',
        'status',
        'items',
        'shipping_address',
        'billing_address',
        'coupon_code',
        'discount',
    ];

    protected $casts = [
        'items' => 'array',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'total_amount' => 'float',
        'taxes' => 'float',
        'shipping_cost' => 'float',
        'discount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the payments for this order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id', 'order_id');
    }

    /**
     * Get transaction logs for this order.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'order_id', 'order_id');
    }

    /**
     * Mark order as paid.
     */
    public function markAsPaid(): void
    {
        $this->update(['status' => self::STATUS_PAID]);
    }

    /**
     * Mark order as failed.
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Mark order as cancelled.
     */
    public function markAsCancelled(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Mark order as shipped.
     */
    public function markAsShipped(): void
    {
        $this->update(['status' => self::STATUS_SHIPPED]);
    }

    /**
     * Mark order as delivered.
     */
    public function markAsDelivered(): void
    {
        $this->update(['status' => self::STATUS_DELIVERED]);
    }
}
