<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id('plan_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 8, 2);
            $table->integer('duration_days');
            $table->json('features');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('max_products')->nullable()->comment('Límite de productos para este plan');
            $table->integer('max_brands')->nullable()->comment('Límite de marcas para este plan');
            $table->timestamps();
            // NOTA: No usamos softDeletes en plans para mantener historial
        });
    }

    public function down()
    {
        Schema::dropIfExists('plans');
    }
};
