<?php

namespace Benchmark\Scenarios\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table      = 'tags';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $fillable = ['name'];

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tags');
    }
}
