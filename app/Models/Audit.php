<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    use HasFactory;

    protected $primaryKey = 'audit_id';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'description',
        'performed_by',
        'ip_address',
        'user_agent',
        'timestamp'
    ];

    // No usamos timestamps automáticos porque tenemos 'timestamp'
    public $timestamps = false;

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'timestamp' => 'datetime',
    ];

    // Relaciones
    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // Relación polimórfica para la entidad auditada
    public function entity()
    {
        return $this->morphTo();
    }

    // Scope para acciones específicas
public function scopeAction(Builder $query, string $action): Builder
{
    return $query->where('action', $action);
}

// Scope para una entidad específica
public function scopeForEntity(Builder $query, string $entityType, $entityId = null): Builder
{
    $query = $query->where('entity_type', $entityType);

    if ($entityId) {
        $query->where('entity_id', $entityId);
    }

    return $query;
}

// Scope CORREGIDO para registros recientes
public function scopeRecent(Builder $query, int $days = 7): Builder
{
    return $query->where('timestamp', '>=', now()->subDays($days));
}
}
