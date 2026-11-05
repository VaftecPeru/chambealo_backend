<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->string('exchange_rate_source', 100)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'original_amount',
                'original_currency',
                'exchange_rate',
                'exchange_rate_source',
                'exchange_rate_at',
            ]);
        });
    }
};
