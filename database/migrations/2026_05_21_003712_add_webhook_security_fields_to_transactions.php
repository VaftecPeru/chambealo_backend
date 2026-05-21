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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('webhook_id')->nullable()->unique()->index(); // For replay attack detection
            $table->string('raw_webhook_payload')->nullable(); // Store raw payload for audit
            $table->boolean('signature_verified')->default(false)->index();
            $table->string('source_ip')->nullable()->index();
            $table->boolean('ip_trusted')->default(false)->index();
            $table->text('webhook_headers')->nullable(); // Store relevant headers
            $table->timestamp('webhook_processed_at')->nullable()->index();
            $table->string('webhook_status')->nullable()->comment('success|failed|malformed|unauthorized|rate_limited');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['webhook_id']);
            $table->dropIndex(['signature_verified']);
            $table->dropIndex(['source_ip']);
            $table->dropIndex(['ip_trusted']);
            $table->dropIndex(['webhook_processed_at']);
            $table->dropColumn([
                'webhook_id',
                'raw_webhook_payload',
                'signature_verified',
                'source_ip',
                'ip_trusted',
                'webhook_headers',
                'webhook_processed_at',
                'webhook_status',
            ]);
        });
    }
};
