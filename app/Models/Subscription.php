<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $primaryKey = 'subscription_id';

    protected $fillable = [
        'user_id',
        'plan_id',
        'start_date',
        'end_date',
        'status',
        'stripe_subscription_id',
        'stripe_customer_id',
        'canceled_at',
        'cancel_reason'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'canceled_at' => 'datetime',
    ];

    // Relaciones
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    // Scope para suscripciones activas
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Scope para suscripciones expiradas
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
                    ->orWhere('end_date', '<', now());
    }

    // Método para verificar si está activa
    public function isActive()
    {
        return $this->status === 'active' && $this->end_date > now();
    }

    // Método CORREGIDO para verificar si está por expirar
    public function isExpiringSoon($days = 7)
    {
        if (!$this->isActive()) {
            return false;
        }

        $endDate = Carbon::parse($this->end_date);
        return $endDate->diffInDays(now()) <= $days;
    }

    // Método adicional para días restantes
    public function daysRemaining()
    {
        $endDate = Carbon::parse($this->end_date);
        return now()->diffInDays($endDate, false); // false para incluir negativos
    }
}
