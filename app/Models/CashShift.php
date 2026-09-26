<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashShift extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['closing_summary' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
}
