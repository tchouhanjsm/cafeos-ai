<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {

        Schema::table('order_items', function (Blueprint $table) {

            if (!Schema::hasColumn('order_items','order_id')) {
                $table->unsignedBigInteger('order_id');
            }

            if (!Schema::hasColumn('order_items','menu_item_id')) {
                $table->unsignedBigInteger('menu_item_id');
            }

            if (!Schema::hasColumn('order_items','item_name')) {
                $table->string('item_name');
            }

            if (!Schema::hasColumn('order_items','unit_price')) {
                $table->decimal('unit_price',10,2);
            }

            if (!Schema::hasColumn('order_items','quantity')) {
                $table->integer('quantity')->default(1);
            }

            if (!Schema::hasColumn('order_items','station_id')) {
                $table->unsignedBigInteger('station_id')->nullable();
            }

            if (!Schema::hasColumn('order_items','status')) {
                $table->string('status')->default('pending');
            }

        });

    }

    public function down(): void
    {

        Schema::table('order_items', function (Blueprint $table) {

            $table->dropColumn([
                'order_id',
                'menu_item_id',
                'item_name',
                'unit_price',
                'quantity',
                'station_id',
                'status'
            ]);

        });

    }

};