<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No cambiar la representacion monetaria
        // si existen pagos historicos.
        $existingPayments = DB::table('payments')->exists();

        if ($existingPayments) {
            throw new RuntimeException(
                'No se puede convertir payments.amount: existen pagos historicos. '
                . 'Se requiere una migracion de datos y una revision de compatibilidad.'
            );
        }

        // Solo se permite cambiar el tipo si la tabla esta vacia.
        DB::statement(
            'ALTER TABLE payments MODIFY amount DECIMAL(15,2) NOT NULL'
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'La reversion de payments.amount requiere una revision manual.'
        );
    }
};
