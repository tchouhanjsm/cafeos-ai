<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Actions\Orders\CreateOrderAction;

class OrderController extends Controller
{

    public function store(Request $request)
    {

        return (new CreateOrderAction())->execute($request);

    }

}