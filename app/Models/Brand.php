<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'brand_id';

    protected $fillable = [
        'user_id',
        'brand_name',
        'description',
        'logo_url',
        'website',
        'visibility_status'
    ];

    // Atributos calculados que se incluyen en el JSON
    protected $appends = ['name'];

    // Alias: brand.name -> brand_name
    public function getNameAttribute()
    {
        return $this->brand_name;
    }

    // Relaciones
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'related');
    }
}