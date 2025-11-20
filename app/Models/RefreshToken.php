<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RefreshToken extends Model
{
    use HasFactory;

    protected $primaryKey = 'token_id';

    protected $fillable = [
        'user_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    // Relación con usuario
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Generar token seguro
    public static function generateToken()
    {
        return Str::random(64);
    }

    // Crear hash seguro del token
    public static function hashToken($token)
    {
        return hash('sha256', $token);
    }

    // Verificar si el token ha expirado
    public function isExpired()
    {
        return $this->expires_at->isPast();
    }

    // Marcar como usado
    public function markAsUsed()
    {
        $this->update(['last_used_at' => now()]);
    }

    // Limpiar tokens expirados
    public static function cleanupExpired()
    {
        return static::where('expires_at', '<', now())->delete();
    }

    // Scope para tokens válidos
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
