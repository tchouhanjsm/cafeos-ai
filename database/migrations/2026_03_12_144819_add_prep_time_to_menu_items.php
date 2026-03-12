<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up()
    {

        Schema::table('menu_items',function(Blueprint $table){

            $table->integer('prep_time')->default(5)->after('price');

        });

    }

    public function down()
    {

        Schema::table('menu_items',function(Blueprint $table){

            $table->dropColumn('prep_time');

        });

    }

};