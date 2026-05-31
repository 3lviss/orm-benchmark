<?php

namespace Benchmark\Scenarios\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table      = 'categories';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $fillable = ['name', 'parent_id'];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
