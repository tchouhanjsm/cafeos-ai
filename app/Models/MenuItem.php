<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{

    protected $fillable = [
        'name',
        'price',
        'station_group_id',
        'prep_time',
        'is_active'
    ];

}