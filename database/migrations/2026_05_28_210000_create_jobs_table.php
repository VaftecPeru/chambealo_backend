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
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                
                // Foreign keys - usar unsignedBigInteger con índices (sin FK constraints por ahora)
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                
                // Job metadata
                $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                    ->default('pending')
                    ->index();
                $table->string('action')->nullable(); // payment, checkout, refund, order, status
                
                // Job data (JSON)
                $table->json('data')->nullable();
                
                // Error handling
                $table->text('error_message')->nullable();
                
                // Timestamps
                $table->timestamps();
                
                // Composite indexes
                $table->index(['order_id', 'status']);
                $table->index(['user_id', 'status']);
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
