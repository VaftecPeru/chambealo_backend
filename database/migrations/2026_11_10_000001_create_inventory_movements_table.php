<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id('movement_id');
            $table->foreignId('product_id')->constrained('products', 'product_id');
            $table->foreignId('performed_by')->constrained('users', 'user_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('type', 16);
            $table->unsignedInteger('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reason', 255);
            $table->string('reference_type', 32)->nullable();
            $table->string('reference_id', 128)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
