<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Review extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'review_id';

    protected $fillable = [
        'product_id',
        'user_id',
        'rating',
        'comment',
        'response',
        'created_at'
    ];

    // Indicar que no usamos los timestamps automáticos de Laravel
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'rating' => 'integer',
    ];

    // Relaciones
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Scope para reviews con rating alto
    public function scopeHighRating($query, $minRating = 4)
    {
        return $query->where('rating', '>=', $minRating);
    }

    // Scope para reviews con respuesta
    public function scopeWithResponse($query)
    {
        return $query->whereNotNull('response');
    }

    // Método para tiempo transcurrido desde la reseña
    public function getTimeAgoAttribute()
    {
        return Carbon::parse($this->created_at)->diffForHumans();
    }

    // Método para verificar si es reseña reciente
    public function isRecent($days = 7)
    {
        $createdAt = Carbon::parse($this->created_at);
        return $createdAt->diffInDays(now()) <= $days;
    }
}
