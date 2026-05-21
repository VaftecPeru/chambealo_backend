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
        Schema::table('payment_logs', function (Blueprint $table) {
            // Signature verification tracking
            $table->boolean('signature_verified')->nullable()->after('webhook_id');
            $table->string('signature_method')->nullable()->after('signature_verified');
            $table->json('signature_details')->nullable()->after('signature_method');
            
            // Timestamp validation tracking
            $table->boolean('timestamp_validated')->nullable()->after('signature_details');
            
            // Replay attack prevention tracking
            $table->string('replay_prevention_id')->nullable()->unique()->after('timestamp_validated');
            
            // New indexes for security-related queries
            $table->index('signature_verified');
            $table->index('replay_prevention_id');
            $table->index(['gateway', 'signature_verified']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'signature_verified']);
            $table->dropIndex('payment_logs_replay_prevention_id_index');
            $table->dropIndex('payment_logs_signature_verified_index');
            $table->dropUnique(['replay_prevention_id']);
            $table->dropColumn([
                'signature_verified',
                'signature_method',
                'signature_details',
                'timestamp_validated',
                'replay_prevention_id',
            ]);
        });
    }
};
