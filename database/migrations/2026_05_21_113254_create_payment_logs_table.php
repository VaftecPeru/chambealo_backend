<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the payment_logs table for detailed auditing and tracking
     * of all payment transactions and webhook events.
     */
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to transactions (nullable - log can exist without transaction)
            $table->unsignedBigInteger('transaction_id')->nullable();
            
            // Event classification
            $table->enum('event_type', [
                'webhook.received',
                'webhook.verification',
                'webhook.processed',
                'webhook.error',
                'payment.initiated',
                'payment.completed',
                'payment.failed',
            ]);
            
            // Status tracking
            $table->enum('status', [
                'success',
                'failed',
                'pending',
                'processing',
                'retry',
            ]);
            
            // Payment gateway identification
            $table->enum('gateway', [
                'paypal',
                'izipay',
            ])->nullable();
            
            // Webhook deduplication ID
            $table->string('webhook_id')->nullable()->unique();
            
            // Request and response payloads
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            
            // HTTP headers for debugging
            $table->json('headers')->nullable();
            
            // Error tracking
            $table->text('error_message')->nullable();
            
            // Client information
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            // Retry tracking
            $table->integer('attempt')->default(1);
            
            // Timestamps
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('set null');
            
            // Indexes for query optimization
            $table->index('transaction_id');
            $table->index('event_type');
            $table->index('webhook_id');
            $table->index('created_at');
            $table->index('status');
            $table->index(['gateway', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
