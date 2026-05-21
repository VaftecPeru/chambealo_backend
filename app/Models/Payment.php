<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable
     *
     * @var array<string>
     */
    protected $fillable = [
        'order_id',
        'email',
        'amount',
        'status',
        'tenant_id',
        'plan_id',
        'user_id',
        'webhook_event',
        'webhook_received_at',
        'payment_method',
        'monto',
        'estado',
        'identificador'
    ];

    /**
     * The attributes that should be cast to native types
     *
     * @var array<string, string>
     */
    protected $dates = [
        'webhook_received_at',
        'created_at',
        'updated_at'
    ];

    /**
     * Get the user that owns this payment
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the plan associated with this payment
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
    }
}

