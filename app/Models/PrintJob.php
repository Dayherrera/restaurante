<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $casts = ['line_styles' => 'array'];

    protected $guarded = ['id'];
}
