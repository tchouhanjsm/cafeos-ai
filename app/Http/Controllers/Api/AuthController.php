<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $pin   = trim($request->input('pin', ''));
        $email = trim($request->input('email', ''));

        if (empty($pin)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'PIN is required',
                'code'    => 422
            ], 422);
        }

        if (!empty($email)) {
            $staff = DB::table('staff')
                ->where('email', $email)
                ->where('is_active', 1)
                ->first();

            if (!$staff || !password_verify($pin, $staff->pin_code)) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Invalid credentials. Please try again.',
                    'code'    => 401
                ], 401);
            }
        } else {
            $allStaff = DB::table('staff')
                ->where('is_active', 1)
                ->get();

            $staff = null;

            foreach ($allStaff as $s) {
                if (password_verify($pin, $s->pin_code)) {
                    $staff = $s;
                    break;
                }
            }

            if (!$staff) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Invalid PIN. Please try again.',
                    'code'    => 401
                ], 401);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'staff' => [
                    'id'    => (int) $staff->id,
                    'name'  => $staff->name,
                    'email' => $staff->email,
                    'role'  => $staff->role,
                ]
            ],
            'message' => 'Login successful',
            'code'    => 200
        ]);
    }
}
