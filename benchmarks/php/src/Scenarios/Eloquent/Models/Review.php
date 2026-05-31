<?php

namespace Benchmark\Scenarios\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table      = 'reviews';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $fillable = ['user_id', 'product_id', 'rating', 'comment', 'created_at'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
