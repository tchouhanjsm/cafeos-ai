<?php

namespace App\Actions\Orders;

use App\Models\Order;

class SendOrderToKitchenAction
{

    public function execute($orderId)
    {

        $order = Order::with('items')->find($orderId);

        if (!$order) {
            return response()->json([
                'success'=>false,
                'message'=>'Order not found'
            ],404);
        }

        if ($order->items->count() == 0) {
            return response()->json([
                'success'=>false,
                'message'=>'Order has no items'
            ],422);
        }

        $order->status = 'sent_to_kitchen';
        $order->save();

        return response()->json([
            'success'=>true,
            'message'=>'Order sent to kitchen',
            'data'=>$order
        ]);

    }

}