<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
    public function scopeAction($query, $action)
    {
        return $query->where('action', $action);
    }

    // Scope para una entidad específica
    public function scopeForEntity($query, $entityType, $entityId = null)
    {
        $query = $query->where('entity_type', $entityType);

        if ($entityId) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    // Scope CORREGIDO para registros recientes
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('timestamp', '>=', now()->subDays($days));
    }

    // Método para tiempo transcurrido
    public function getTimeAgoAttribute()
    {
        return Carbon::parse($this->timestamp)->diffForHumans();
    }

    // Método para verificar si es registro reciente
    public function isRecent($hours = 24)
    {
        $timestamp = Carbon::parse($this->timestamp);
        return $timestamp->diffInHours(now()) <= $hours;
    }
}
