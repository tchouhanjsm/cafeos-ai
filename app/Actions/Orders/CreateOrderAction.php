<?php

namespace App\Actions\Orders;

use App\Models\Order;

class CreateOrderAction
{

    public function execute($request)
    {

        $validated = $request->validate([

            'order_type' => 'nullable|string|in:dine_in,takeaway,delivery',
            'guest_count' => 'nullable|integer|min:1',
            'table_id' => 'nullable|integer'

        ]);

        $order = Order::create([

            'order_number' => 'ORD-' . time(),

            'table_id' => $validated['table_id'] ?? null,

            'staff_id' => auth('api')->id(),

            'order_type' => $validated['order_type'] ?? 'dine_in',

            'guest_count' => $validated['guest_count'] ?? 1,

            'status' => 'open'

        ]);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);

    }

}