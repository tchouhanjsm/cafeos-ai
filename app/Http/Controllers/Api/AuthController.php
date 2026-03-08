<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class AuthController extends Controller
{

    public function login(Request $request)
    {

        $pin = $request->input('pin');

        if(!$pin){

            return response()->json([
                'success'=>false,
                'message'=>'PIN required'
            ],422);

        }

        $users = User::where('is_active',1)->get();

        $user = $users->first(function($u) use ($pin){

            return password_verify($pin,$u->pin_code);

        });

        if(!$user){

            return response()->json([
                'success'=>false,
                'message'=>'Invalid PIN'
            ],401);

        }

        $token = auth('api')->login($user);

        return response()->json([

            'success'=>true,
            'token'=>$token,

            'staff'=>[
                'id'=>$user->id,
                'name'=>$user->name,
                'role'=>$user->role
            ]

        ]);

    }

}