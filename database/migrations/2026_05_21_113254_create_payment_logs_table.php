<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ========== SEGUNDO CÓDIGO AGREGADO (transactions) ==========
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique(); // ID del gateway de pago
            $table->string('order_id')->unique()->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PEN');
            $table->string('payment_method'); // izipay, culqi, etc.
            $table->enum('status', [
                'pending', 'processing', 'completed', 
                'failed', 'refunded', 'cancelled'
            ])->default('pending');
            $table->json('customer_data');
            $table->json('payment_details')->nullable();
            $table->text('error_message')->nullable();
            $table->string('reference_code')->nullable(); // Código de referencia para el cliente
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index('reference_code');
        });
        // ========== FIN DEL SEGUNDO CÓDIGO ==========

        // ========== PRIMER CÓDIGO ORIGINAL (payment_logs) ==========
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            
            // === COLUMNAS ORIGINALES (TODAS CONSERVADAS) ===
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
            
            // === NUEVAS COLUMNAS DEL SEGUNDO CÓDIGO ===
            $table->string('type'); // connection, job_process, validation_error, payment_result
            $table->string('job_id')->nullable()->index();
            $table->string('order_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->timestamp('logged_at');
            
            // === TIMESTAMPS ORIGINALES ===
            $table->timestamps();
            
            // === FOREIGN KEYS ORIGINALES ===
            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('set null');
            
            // === ÍNDICES ORIGINALES ===
            $table->index('transaction_id');
            $table->index('event_type');
            $table->index('webhook_id');
            $table->index('created_at');
            $table->index('status');
            $table->index(['gateway', 'created_at']);
            
            // === NUEVOS ÍNDICES DEL SEGUNDO CÓDIGO ===
            $table->index(['type', 'logged_at']);
            $table->index(['order_id', 'type']);
        });
        // ========== FIN DEL PRIMER CÓDIGO ==========
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
        Schema::dropIfExists('transactions');
    }
};