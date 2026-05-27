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
        // Update payments table to add missing fields
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                // Add fields if they don't exist
                if (!Schema::hasColumn('payments', 'gateway')) {
                    $table->string('gateway')->nullable()->after('status');
                }
                if (!Schema::hasColumn('payments', 'payment_id')) {
                    $table->string('payment_id')->nullable()->unique()->after('gateway');
                }
                if (!Schema::hasColumn('payments', 'currency')) {
                    $table->string('currency', 3)->default('USD')->after('amount');
                }
                if (!Schema::hasColumn('payments', 'raw_response')) {
                    $table->json('raw_response')->nullable()->after('currency');
                }
                if (!Schema::hasColumn('payments', 'user_id')) {
                    $table->bigInteger('user_id')->nullable()->index();
                    $table->foreign('user_id')->references('user_id')->on('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('payments', 'tenant_id')) {
                    $table->string('tenant_id')->nullable()->index();
                }
                if (!Schema::hasColumn('payments', 'webhook_event')) {
                    $table->string('webhook_event')->nullable();
                }
                if (!Schema::hasColumn('payments', 'webhook_received_at')) {
                    $table->timestamp('webhook_received_at')->nullable();
                }
            });
        }

        // Update transactions table to add payment_id foreign key if needed
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'payment_id')) {
                    $table->foreignId('payment_id')->nullable()->constrained('payments');
                }
                if (!Schema::hasColumn('transactions', 'raw_data')) {
                    $table->json('raw_data')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Drop payment_id foreign key if exists
                $indexName = 'transactions_payment_id_foreign';
                try {
                    $table->dropForeign([$indexName]);
                } catch (\Exception $e) {
                    // Foreign key might not exist
                }
                if (Schema::hasColumn('transactions', 'payment_id')) {
                    $table->dropColumn('payment_id');
                }
                if (Schema::hasColumn('transactions', 'raw_data')) {
                    $table->dropColumn('raw_data');
                }
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                // Drop user_id foreign key if exists
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Exception $e) {
                    // Foreign key might not exist
                }
                if (Schema::hasColumn('payments', 'user_id')) {
                    $table->dropColumn('user_id');
                }
                if (Schema::hasColumn('payments', 'gateway')) {
                    $table->dropColumn('gateway');
                }
                if (Schema::hasColumn('payments', 'payment_id')) {
                    $table->dropColumn('payment_id');
                }
                if (Schema::hasColumn('payments', 'currency')) {
                    $table->dropColumn('currency');
                }
                if (Schema::hasColumn('payments', 'raw_response')) {
                    $table->dropColumn('raw_response');
                }
                if (Schema::hasColumn('payments', 'tenant_id')) {
                    $table->dropColumn('tenant_id');
                }
                if (Schema::hasColumn('payments', 'webhook_event')) {
                    $table->dropColumn('webhook_event');
                }
                if (Schema::hasColumn('payments', 'webhook_received_at')) {
                    $table->dropColumn('webhook_received_at');
                }
            });
        }
    }
};
