<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds security-related fields to payment_logs table for webhook verification
     * tracking across all gateways (PayPal, Izipay, Mercado Pago).
     */
    public function up(): void
    {
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // Signature verification tracking
                if (!Schema::hasColumn('payment_logs', 'signature_verified')) {
                    $table->boolean('signature_verified')->nullable()->after('webhook_id');
                }
                if (!Schema::hasColumn('payment_logs', 'signature_method')) {
                    $table->string('signature_method')->nullable()->after('signature_verified');
                }
                if (!Schema::hasColumn('payment_logs', 'signature_details')) {
                    $table->json('signature_details')->nullable()->after('signature_method');
                }
                
                // Timestamp validation tracking
                if (!Schema::hasColumn('payment_logs', 'timestamp_validated')) {
                    $table->boolean('timestamp_validated')->nullable()->after('signature_details');
                }
                
                // Replay attack prevention tracking
                if (!Schema::hasColumn('payment_logs', 'replay_prevention_id')) {
                    $table->string('replay_prevention_id')->nullable()->unique()->after('timestamp_validated');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // Drop columns if they exist
                $columns = ['signature_verified', 'signature_method', 'signature_details', 'timestamp_validated', 'replay_prevention_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('payment_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
