<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id('product_id');
            $table->foreignId('brand_id')->constrained('brands', 'brand_id');
            $table->foreignId('category_id')->constrained('categories', 'category_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->enum('status', ['active', 'inactive', 'out_of_stock', 'discontinued'])->default('active');
            $table->json('specifications')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Índices para mejor performance
            $table->index(['status', 'stock']);
            $table->index(['category_id', 'brand_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
