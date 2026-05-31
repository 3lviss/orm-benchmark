<?php

namespace Benchmark\Scenarios\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table      = 'order_items';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $fillable = ['order_id', 'product_id', 'quantity', 'price'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
