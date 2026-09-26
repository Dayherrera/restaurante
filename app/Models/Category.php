<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['visible_in_pos' => true];

    protected $casts = ['visible_in_pos' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
