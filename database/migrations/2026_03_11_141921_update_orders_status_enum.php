<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up()
    {

        Schema::table('orders',function(Blueprint $table){

            $table->enum('status',[
                'open',
                'sent_to_kitchen',
                'cooking',
                'ready',
                'served',
                'billed',
                'paid',
                'cancelled'
            ])->default('open')->change();

        });

    }

    public function down()
    {

        Schema::table('orders',function(Blueprint $table){

            $table->string('status')->change();

        });

    }

};