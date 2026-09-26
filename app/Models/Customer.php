<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = ['id'];

    public function address(): string
    {
        return implode(', ', array_filter([trim(($this->street ?? '').' '.($this->number ?? '')), $this->neighborhood, $this->city, $this->state, $this->references]));
    }
}
