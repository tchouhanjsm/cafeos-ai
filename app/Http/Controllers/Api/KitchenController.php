<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;

class KitchenController extends Controller
{

    public function queue()
    {

        $orders = Order::with(['items'])
            ->whereIn('status',[
                'sent_to_kitchen',
                'cooking',
                'ready'
            ])
            ->orderBy('created_at','asc')
            ->get();

        return response()->json([
            'success'=>true,
            'data'=>$orders
        ]);

    }

}