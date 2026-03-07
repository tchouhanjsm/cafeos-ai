<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class StaffSeeder extends Seeder
{
    public function run(): void
    {

        $staff = [

            [
                'name' => 'Admin',
                'email' => 'admin@cafeos.com',
                'pin' => '1234',
                'role' => 'admin'
            ],

            [
                'name' => 'Chef',
                'email' => 'chef@cafeos.com',
                'pin' => '4567',
                'role' => 'chef'
            ],

            [
                'name' => 'Waiter',
                'email' => 'waiter@cafeos.com',
                'pin' => '7890',
                'role' => 'waiter'
            ],

            [
                'name' => 'Cashier',
                'email' => 'cashier@cafeos.com',
                'pin' => '0123',
                'role' => 'cashier'
            ]

        ];

        foreach ($staff as $s) {

            User::updateOrCreate(

                ['email' => $s['email']],

                [
                    'name' => $s['name'],
                    'password' => bcrypt('password'),
                    'pin_code' => password_hash($s['pin'], PASSWORD_BCRYPT),
                    'role' => $s['role'],
                    'is_active' => 1
                ]

            );

        }

    }
}