<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['scheduled_date' => 'date', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2'];

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
