<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            try {
                // Add FK to orders
                if (!$this->hasForeignKey('jobs', 'order_id', 'orders')) {
                    $table->foreign('order_id')
                        ->references('id')
                        ->on('orders')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }

            try {
                // Add FK to users - note: users table has user_id as primary key
                if (!$this->hasForeignKey('jobs', 'user_id', 'users')) {
                    $table->foreign('user_id')
                        ->references('user_id')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }

            try {
                // Add FK to transactions (nullable)
                if (!$this->hasForeignKey('jobs', 'transaction_id', 'transactions')) {
                    $table->foreign('transaction_id')
                        ->references('id')
                        ->on('transactions')
                        ->onDelete('set null')
                        ->onUpdate('cascade');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            if ($this->hasForeignKey('jobs', 'order_id', 'orders')) {
                $table->dropForeign(['order_id']);
            }
            if ($this->hasForeignKey('jobs', 'user_id', 'users')) {
                $table->dropForeign(['user_id']);
            }
            if ($this->hasForeignKey('jobs', 'transaction_id', 'transactions')) {
                $table->dropForeign(['transaction_id']);
            }
        });
    }

    /**
     * Helper method to check if foreign key exists
     */
    private function hasForeignKey(string $table, string $column, string $referencesTable): bool
    {
        $keyConstraints = \DB::select(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? AND TABLE_SCHEMA = ?",
            [$table, $column, $referencesTable, \DB::getDatabaseName()]
        );

        return !empty($keyConstraints);
    }
};
