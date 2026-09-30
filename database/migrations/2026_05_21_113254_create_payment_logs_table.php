<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->enum('event_type', [
                'webhook.received',
                'webhook.verification',
                'webhook.processed',
                'webhook.error',
                'payment.initiated',
                'payment.completed',
                'payment.failed',
                'security.event',
                'security.replay_attempt',
                'security.signature_verification',
            ]);

            $table->enum('status', [
                'success',
                'failed',
                'pending',
                'processing',
                'retry',
            ]);

            $table->enum('gateway', [
                'paypal',
                'izipay',
                'mercadopago',
            ])->nullable();

            $table->string('webhook_id')->nullable()->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('headers')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->integer('attempt')->default(1);

            $table->string('type');
            $table->string('job_id')->nullable()->index();
            $table->string('order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id')->nullable()->index();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();

            $table->index('transaction_id');
            $table->index('event_type');
            $table->index('webhook_id');
            $table->index('created_at');
            $table->index('status');
            $table->index(['gateway', 'created_at']);
            $table->index(['type', 'logged_at']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
