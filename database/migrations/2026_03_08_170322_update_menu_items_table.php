<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {

            if (!Schema::hasColumn('menu_items','name')) {
                $table->string('name')->after('id');
            }

            if (!Schema::hasColumn('menu_items','price')) {
                $table->decimal('price',10,2)->default(0);
            }

            if (!Schema::hasColumn('menu_items','station_group_id')) {
                $table->unsignedBigInteger('station_group_id')->nullable();
            }

            if (!Schema::hasColumn('menu_items','is_active')) {
                $table->boolean('is_active')->default(true);
            }

        });
    }

    public function down(): void
    {
        // optional rollback
    }
};