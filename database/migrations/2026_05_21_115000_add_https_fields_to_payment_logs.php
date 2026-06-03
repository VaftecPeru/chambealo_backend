<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds HTTPS/TLS verification fields to payment_logs table
     * for tracking secure webhook connections.
     */
    public function up(): void
    {
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // HTTPS/TLS verification
                if (!Schema::hasColumn('payment_logs', 'https_verified')) {
                    $table->boolean('https_verified')->nullable()->after('replay_prevention_id');
                }
                if (!Schema::hasColumn('payment_logs', 'tls_version')) {
                    $table->string('tls_version')->nullable()->after('https_verified');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropIndex('payment_logs_https_verified_index');
            $table->dropColumn([
                'https_verified',
                'tls_version',
            ]);
        });
    }
};
