<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'price' => 'decimal:2'];

    public function modifierGroups()
    {
        return $this->hasMany(ModifierGroup::class)->orderBy('id');
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function printArea()
    {
        return $this->belongsTo(PrintArea::class);
    }
}
