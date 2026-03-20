<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\KitchenTimerService;

class KitchenController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | Full Kitchen Queue
    |--------------------------------------------------------------------------
    */

    public function queue()
{

    $timer = new KitchenTimerService();

    $items = \App\Models\OrderItem::with('menuItem','order')
        ->whereIn('status',['pending','cooking'])
        ->orderBy('created_at','asc')
        ->get();

    $items = $items->map(function($item) use ($timer){

        $timing = $timer->calculate($item);

        return [
            'id'=>$item->id,
            'order_id'=>$item->order_id,
            'item_name'=>$item->item_name,
            'quantity'=>$item->quantity,
            'station_id'=>$item->station_id,
            'status'=>$item->status,
            'cooking_started_at'=>$item->cooking_started_at,
            'timer'=>$timing
        ];

    });

    return response()->json([
        'success'=>true,
        'data'=>$items
    ]);

}


    /*
    |--------------------------------------------------------------------------
    | Station Queue
    |--------------------------------------------------------------------------
    */

    public function stationQueue($id)
    {

        $items = OrderItem::where('station_id',$id)
            ->whereIn('status',['pending','cooking'])
            ->orderBy('created_at','asc')
            ->get();

        return response()->json([
            'success'=>true,
            'station_id'=>$id,
            'data'=>$items
        ]);

    }


    /*
    |--------------------------------------------------------------------------
    | Start Cooking
    |--------------------------------------------------------------------------
    */

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

public function delayed()
{

    $items = \App\Models\OrderItem::with('menuItem')
        ->where('status','cooking')
        ->get()
        ->filter(function($item){

            if(!$item->cooking_started_at){
                return false;
            }

            $elapsed = now()->diffInMinutes($item->cooking_started_at);

            return $elapsed > ($item->menuItem->prep_time ?? 5);

        });

    return response()->json([
        'success'=>true,
        'data'=>$items->values()
    ]);

}

    /*
    |--------------------------------------------------------------------------
    | Mark Ready
    |--------------------------------------------------------------------------
    */

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