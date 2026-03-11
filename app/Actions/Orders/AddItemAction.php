<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Services\KitchenRoutingService;
use Illuminate\Support\Facades\DB;

class AddItemAction
{

    public function execute($request, $orderId)
    {

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        if (!$request->menu_item_id) {
            return response()->json([
                'success' => false,
                'message' => 'menu_item_id is required'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Order
        |--------------------------------------------------------------------------
        */

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Menu Item
        |--------------------------------------------------------------------------
        */

        $menuItem = MenuItem::find($request->menu_item_id);

        if (!$menuItem) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found'
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Smart Kitchen Routing Engine
        |--------------------------------------------------------------------------
        */

        $routing = new KitchenRoutingService();

        $stationId = $routing->resolveStation($menuItem->station_group_id);

        /*
        |--------------------------------------------------------------------------
        | Quantity Safety
        |--------------------------------------------------------------------------
        */

        $quantity = max(1, (int) $request->quantity);

        /*
        |--------------------------------------------------------------------------
        | Create Item Transaction
        |--------------------------------------------------------------------------
        */

        try {

            $item = DB::transaction(function () use ($order, $menuItem, $quantity, $stationId) {

                return OrderItem::create([
                    'order_id'      => $order->id,
                    'menu_item_id'  => $menuItem->id,
                    'item_name'     => $menuItem->name,
                    'unit_price'    => $menuItem->price,
                    'quantity'      => $quantity,
                    'station_id'    => $stationId,
                    'status'        => 'pending'
                ]);

            });

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to add item',
                'error' => $e->getMessage()
            ], 500);

        }

        /*
        |--------------------------------------------------------------------------
        | Success Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'data' => $item
        ]);

    }

}