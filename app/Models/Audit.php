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
        'tenant_id',
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'description',
        'performed_by',
        'ip_address',
        'user_agent',
        'timestamp',
    ];

    // No usamos timestamps automáticos porque tenemos 'timestamp'
    public $timestamps = false;

    protected $casts = [
        'tenant_id' => 'integer',
        'entity_id' => 'integer',
        'performed_by' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'timestamp' => 'datetime',
    ];

    // Relaciones
    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    // Relación polimórfica para la entidad auditada
    public function entity()
    {
        return $this->morphTo();
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForEntity(Builder $query, string $entityType, $entityId = null): Builder
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', (int) $entityId);
        }

        return $query;
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
        });
    }

    public function scopePerformedBy(Builder $query, int $userId): Builder
    {
        return $query->where('performed_by', $userId);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays($days));
    }
}
