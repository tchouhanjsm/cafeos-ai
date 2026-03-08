<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\AddItemAction;

class OrderController extends Controller
{

    public function store(Request $request)
    {

        return (new CreateOrderAction())->execute($request);

    }


    public function addItem(Request $request, $id)
    {

        $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'nullable|integer|min:1'
        ]);

        return (new AddItemAction())->execute($request,$id);

    }

}