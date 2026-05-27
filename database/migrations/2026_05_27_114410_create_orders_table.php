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
            $table->string('order_id')->unique(); // ID externo
            $table->unsignedBigInteger('user_id');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('taxes', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->enum('status', [
                'cart', 'checkout', 'payment_pending', 
                'paid', 'shipped', 'delivered', 'cancelled'
            ])->default('cart');
            $table->json('items');
            $table->json('shipping_address');
            $table->json('billing_address');
            $table->string('coupon_code')->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('created_at');
            
            // Foreign key
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
