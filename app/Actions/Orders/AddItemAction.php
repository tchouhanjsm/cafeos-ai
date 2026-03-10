<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\KitchenStation;
use Illuminate\Support\Facades\DB;

class AddItemAction
{

    public function execute($request, $orderId)
    {

        // Validate request
        if (!$request->menu_item_id) {
            return response()->json([
                'success' => false,
                'message' => 'menu_item_id is required'
            ], 422);
        }

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $menuItem = MenuItem::find($request->menu_item_id);

        if (!$menuItem) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found'
            ], 404);
        }

        $stationId = null;

        if ($menuItem->station_group_id) {

            $station = KitchenStation::where('group_id', $menuItem->station_group_id)
                ->orderBy('id')
                ->first();

            if ($station) {
                $stationId = $station->id;
            }
        }

        $quantity = max(1, (int) $request->quantity);

        $item = DB::transaction(function () use ($order, $menuItem, $quantity, $stationId) {

            return OrderItem::create([
                'order_id' => $order->id,
                'menu_item_id' => $menuItem->id,
                'item_name' => $menuItem->name,
                'unit_price' => $menuItem->price,
                'quantity' => $quantity,
                'station_id' => $stationId,
                'status' => 'pending'
            ]);

        });

        return response()->json([
            'success' => true,
            'data' => $item
        ]);

    }

}