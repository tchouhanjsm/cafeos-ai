<?php

namespace App\Actions\Orders;

use App\Models\Order;

class CreateOrderAction
{

    public function execute($request)
    {

        $order = Order::create([

            'order_number' => 'ORD-'.time(),
            'table_id' => $request->table_id ?? null,
            'staff_id' => auth('api')->id(),
            'order_type' => $request->order_type,
            'guest_count' => $request->guest_count ?? 1,
            'status' => 'open'

        ]);

        return response()->json([
            'success'=>true,
            'data'=>$order
        ]);

    }

}