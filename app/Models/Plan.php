<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $primaryKey = 'plan_id';

    protected $fillable = [
        'name',
        'slug',
        'price',
        'duration_days',
        'features',
        'description',
        'is_active',
        'max_products',
        'max_brands'
    ];

    protected $casts = [
        'features' => 'array',
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'max_products' => 'integer',
        'max_brands' => 'integer',
    ];

    // Relaciones
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    // Scope para planes activos
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Método para verificar si el plan es gratuito
    public function isFree()
    {
        return $this->price == 0;
    }
}
