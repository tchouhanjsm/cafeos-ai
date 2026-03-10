<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('kitchen_stations', function (Blueprint $table) {

            if (!Schema::hasColumn('kitchen_stations','name')) {
                $table->string('name')->after('id');
            }

            if (!Schema::hasColumn('kitchen_stations','group_id')) {
                $table->unsignedBigInteger('group_id')->nullable();
            }

            if (!Schema::hasColumn('kitchen_stations','is_active')) {
                $table->boolean('is_active')->default(true);
            }

        });
    }

    public function down(): void
    {
        // optional rollback
    }

};