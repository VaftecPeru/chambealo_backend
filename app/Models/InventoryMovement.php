<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $primaryKey = 'movement_id';

    protected $fillable = [
        'product_id', 'performed_by', 'tenant_id', 'type', 'quantity',
        'stock_before', 'stock_after', 'reason', 'reference_type', 'reference_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
