<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'media_id';

    protected $fillable = [
        'related_type',
        'related_id',
        'file_url',
        'file_name',
        'file_type',
        'file_size',
        'collection',
        'order_column',
        'custom_properties',
        'uploaded_at'
    ];

    protected $casts = [
        'custom_properties' => 'array',
        'file_size' => 'integer',
        'order_column' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    // Relación polimórfica
    public function related()
    {
        return $this->morphTo();
    }

    // Scope para un tipo específico de archivo
    public function scopeType($query, $type)
    {
        return $query->where('file_type', 'like', "{$type}%");
    }

    // Scope para una colección específica
    public function scopeCollection($query, $collection)
    {
        return $query->where('collection', $collection);
    }
}
