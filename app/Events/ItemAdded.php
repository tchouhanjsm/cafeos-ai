<?php

namespace App\Events;

use App\Models\OrderItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ItemAdded
{
    use Dispatchable, SerializesModels;

    public $item;

    public function __construct(OrderItem $item)
    {
        $this->item = $item;
    }
}