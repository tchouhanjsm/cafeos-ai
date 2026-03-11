<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Order;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\AddItemAction;
use App\Actions\Orders\SendOrderToKitchenAction;

class OrderController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | Create Order
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        return (new CreateOrderAction())->execute($request);
    }


    /*
    |--------------------------------------------------------------------------
    | Add Item To Order
    |--------------------------------------------------------------------------
    */

    public function addItem(Request $request, $id)
    {

        $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'nullable|integer|min:1'
        ]);

        return (new AddItemAction())->execute($request, $id);

    }


    /*
    |--------------------------------------------------------------------------
    | Show Order
    |--------------------------------------------------------------------------
    */

    public function show($id)
    {

        $order = Order::with('items')->find($id);

        if (!$order) {

            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ],404);

        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);

    }


    /*
    |--------------------------------------------------------------------------
    | Send Order To Kitchen
    |--------------------------------------------------------------------------
    */

    public function sendToKitchen($id)
    {
        return (new SendOrderToKitchenAction())->execute($id);
    }

}