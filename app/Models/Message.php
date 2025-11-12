<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'message_id';

    protected $fillable = [
        'sender_id',
        'received_id',
        'subject',
        'body',
        'is_read',
        'read_at',
        'sent_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    // Relaciones
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_id');
    }

    // Scope para mensajes no leídos
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    // Scope para mensajes de un usuario específico
    public function scopeForUser($query, $userId)
    {
        return $query->where('received_id', $userId);
    }

    // Método para marcar como leído
    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    // Método CORREGIDO para verificar si es reciente
    public function isRecent($hours = 24)
    {
        $sentAt = Carbon::parse($this->sent_at);
        return $sentAt->diffInHours(now()) <= $hours;
    }

    // Método adicional para tiempo transcurrido
    public function timeAgo()
    {
        $sentAt = Carbon::parse($this->sent_at);
        return $sentAt->diffForHumans();
    }
}
