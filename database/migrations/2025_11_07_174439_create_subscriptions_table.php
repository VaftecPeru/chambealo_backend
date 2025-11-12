<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id('subscription_id');
            $table->foreignId('user_id')->constrained('users', 'user_id');
            $table->foreignId('plan_id')->constrained('plans', 'plan_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'canceled', 'expired', 'pending'])->default('pending');
            $table->string('stripe_subscription_id')->nullable()->comment('ID de suscripción en Stripe');
            $table->string('stripe_customer_id')->nullable()->comment('ID de cliente en Stripe');
            $table->timestamp('canceled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            // NOTA: No usamos softDeletes en subscriptions para mantener historial

            // Índices para mejor performance
            $table->index(['user_id', 'status']);
            $table->index(['end_date', 'status']);
            $table->index(['stripe_subscription_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
};
