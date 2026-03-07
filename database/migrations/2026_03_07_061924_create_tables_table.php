<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Table Information
            |--------------------------------------------------------------------------
            */

            $table->string('name');                // Example: T1, T2, Garden1
            $table->integer('capacity')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Table Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'available',
                'occupied',
                'reserved',
                'cleaning',
            ])->default('available');

            /*
            |--------------------------------------------------------------------------
            | Current Order
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('current_order_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Time Tracking
            |--------------------------------------------------------------------------
            */

            $table->timestamp('occupied_at')->nullable();
            $table->timestamp('last_cleared_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
