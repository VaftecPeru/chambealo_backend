<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add FK to users
            try {
                if (!$this->hasForeignKey('orders', 'user_id', 'users')) {
                    $table->foreign('user_id')
                        ->references('user_id')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist or constraint error
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if ($this->hasForeignKey('orders', 'user_id', 'users')) {
                $table->dropForeign(['user_id']);
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
