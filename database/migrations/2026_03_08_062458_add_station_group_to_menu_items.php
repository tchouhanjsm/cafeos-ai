<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {

            $table->unsignedBigInteger('station_group_id')->nullable();

        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {

            $table->dropColumn('station_group_id');

        });
    }

};