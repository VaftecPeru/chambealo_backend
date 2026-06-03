<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo crear si no existe
        if (!Schema::hasTable('payment_logs')) {
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
                $table->unsignedBigInteger('user_id')->nullable()->index(); // Solo índice, no FK
                $table->string('session_id')->nullable()->index();
                $table->timestamp('logged_at');
                
                // === TIMESTAMPS ORIGINALES ===
                $table->timestamps();
                
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
            
            // Add foreign key for transaction_id only
            Schema::table('payment_logs', function (Blueprint $table) {
                try {
                    $table->foreign('transaction_id')
                        ->references('id')
                        ->on('transactions')
                        ->onDelete('set null');
                } catch (\Exception $e) {
                    // Foreign key might fail, skip
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // Try to drop foreign keys safely
                $indexes = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_NAME = 'payment_logs' AND COLUMN_NAME IN ('transaction_id', 'user_id')
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                
                foreach ($indexes as $index) {
                    try {
                        $table->dropForeign($index->CONSTRAINT_NAME);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
                
                // Drop columns if they exist
                if (Schema::hasColumn('payment_logs', 'transaction_id')) {
                    $table->dropColumn('transaction_id');
                }
                if (Schema::hasColumn('payment_logs', 'event_type')) {
                    $table->dropColumn('event_type');
                }
                // ... rest of columns
            });
        }
    }
};