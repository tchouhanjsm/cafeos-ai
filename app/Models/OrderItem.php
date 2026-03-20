<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'item_name',
        'unit_price',
        'quantity',
        'station_id',
        'status'
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */public function getCookingSecondsAttribute()
{

    if(!$this->cooking_started_at){
        return 0;
    }

    return now()->diffInSeconds($this->cooking_started_at);

}

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function station()
    {
        return $this->belongsTo(KitchenStation::class, 'station_id');
    }

}