<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['required' => 'boolean'];

    public function options()
    {
        return $this->belongsToMany(Product::class, 'modifier_group_options')->orderBy('modifier_group_options.id');
    }
}
