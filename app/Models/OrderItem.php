<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['selected_options' => 'array', 'is_cancelled' => 'boolean'];
}
