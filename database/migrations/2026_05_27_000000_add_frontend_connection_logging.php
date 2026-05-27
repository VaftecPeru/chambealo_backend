<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Agregar índices optimizados para frontend_connection logs
     */
    public function up(): void
    {
        // Verificar que la tabla existe antes de modificar
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // Índice compuesto para queries de tipo 'frontend_connection'
                // Permite filtrar por type y período de tiempo eficientemente
                if (!Schema::hasColumn('payment_logs', 'type')) {
                    $table->string('type')->nullable(); // Fallback if column missing
                }
                
                // Este índice ya existe en la migración original (linea 103)
                // Pero agregamos el comentario para documentación
                // $table->index(['type', 'logged_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverting as indices are optional optimizations
    }
};
