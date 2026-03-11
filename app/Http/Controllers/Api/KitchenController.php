<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;

class KitchenController extends Controller
{

    public function queue()
    {

        $items = OrderItem::whereIn('status',[
            'pending',
            'cooking',
            'ready'
        ])
        ->orderBy('created_at','asc')
        ->get();

        return response()->json([
            'success'=>true,
            'data'=>$items
        ]);

    }


    public function startCooking($id)
    {

        $item = OrderItem::find($id);

        if(!$item){

            return response()->json([
                'success'=>false,
                'message'=>'Item not found'
            ],404);

        }

        $item->status = 'cooking';
        $item->cooking_started_at = now();
        $item->save();

        return response()->json([
            'success'=>true,
            'data'=>$item
        ]);

    }


    public function markReady($id)
    {

        $item = OrderItem::find($id);

        if(!$item){

            return response()->json([
                'success'=>false,
                'message'=>'Item not found'
            ],404);

        }

        $item->status = 'ready';
        $item->ready_at = now();
        $item->save();

        return response()->json([
            'success'=>true,
            'data'=>$item
        ]);

    }

}