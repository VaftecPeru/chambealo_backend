<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'tenant_id',
        'plan_id',
        'subtotal',
        'tax',
        'discount',
        'total',
        'status',
        'payment_method',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'total' => 'float',
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
     * Get the plan associated with the order.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
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
        $this->update(['status' => 'paid']);
    }

    /**
     * Mark order as failed.
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    /**
     * Mark order as cancelled.
     */
    public function markAsCancelled(): void
    {
        $this->update(['status' => 'cancelled']);
    }
}
