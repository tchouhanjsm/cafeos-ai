<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {

            $table->id();

            $table->string('order_number');

            $table->unsignedBigInteger('table_id')->nullable();

            $table->unsignedBigInteger('staff_id');

            $table->unsignedBigInteger('shift_id')->nullable();

            $table->string('order_type');

            $table->integer('guest_count')->default(1);

            $table->string('status')->default('open');

            $table->text('notes')->nullable();

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};