<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->string('transaction_id')->unique();
                $table->string('order_id');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('tenant_id')->nullable()->index();
                $table->string('payment_method'); // 'izipay', 'paypal', 'mercadopago'
                $table->string('process'); // 'create_session', 'confirm_payment', 'webhook'
                $table->string('status'); // 'success', 'failed', 'pending'
                $table->decimal('amount', 15, 2);
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->string('provider_transaction_id')->nullable()->index();
                $table->string('webhook_event')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                // Indexes
                $table->index('transaction_id');
                $table->index('order_id');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

