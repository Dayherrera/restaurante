<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['production_released_at'=>'datetime', 'scheduled_date' => 'date', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2'];

    public function getOrderNumberAttribute($value): string
    {
        $value = (string) $value;
        return ctype_digit($value) ? str_pad($value, 7, '0', STR_PAD_LEFT) : $value;
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function driver()
    {
        return $this->belongsTo(DeliveryDriver::class, 'delivery_driver_id');
    }
}
