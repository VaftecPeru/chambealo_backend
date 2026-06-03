<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Solo agregar columnas sin intentar crear índices que podrían no existir
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Agregar solo si las columnas no existen
                if (!Schema::hasColumn('transactions', 'webhook_id')) {
                    $table->string('webhook_id')->nullable()->unique()->after('id');
                }
                if (!Schema::hasColumn('transactions', 'raw_webhook_payload')) {
                    $table->string('raw_webhook_payload')->nullable()->after('webhook_id');
                }
                if (!Schema::hasColumn('transactions', 'signature_verified')) {
                    $table->boolean('signature_verified')->default(false)->index()->after('raw_webhook_payload');
                }
                if (!Schema::hasColumn('transactions', 'source_ip')) {
                    $table->string('source_ip')->nullable()->index()->after('signature_verified');
                }
                if (!Schema::hasColumn('transactions', 'ip_trusted')) {
                    $table->boolean('ip_trusted')->default(false)->index()->after('source_ip');
                }
                if (!Schema::hasColumn('transactions', 'webhook_headers')) {
                    $table->text('webhook_headers')->nullable()->after('ip_trusted');
                }
                if (!Schema::hasColumn('transactions', 'webhook_processed_at')) {
                    $table->timestamp('webhook_processed_at')->nullable()->index()->after('webhook_headers');
                }
                if (!Schema::hasColumn('transactions', 'webhook_status')) {
                    $table->string('webhook_status')->nullable()->comment('success|failed|malformed|unauthorized|rate_limited')->after('webhook_processed_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = ['webhook_id', 'raw_webhook_payload', 'signature_verified', 'source_ip', 'ip_trusted', 'webhook_headers', 'webhook_processed_at', 'webhook_status'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

