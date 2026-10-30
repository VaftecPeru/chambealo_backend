<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class PenToUsdConverter
{
    /**
     * Convierte un importe PEN a USD.
     *
     * rate: cantidad de soles por 1 USD.
     */
    public function convert(string $amountPen, string $rate): string
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountPen)) {
            throw new InvalidArgumentException(
                'Importe PEN invalido.'
            );
        }

        if (!preg_match('/^\d+(\.\d{1,8})?$/', $rate)) {
            throw new InvalidArgumentException(
                'Tipo de cambio invalido.'
            );
        }

        if (bccomp($amountPen, '0', 2) <= 0 ||
            bccomp($rate, '0', 8) <= 0) {
            throw new InvalidArgumentException(
                'El importe y la tasa deben ser positivos.'
            );
        }
$usd = bcdiv($amountPen, $rate, 8);

// Redondeo al centavo, mitad hacia arriba.
$roundedUsd = bcadd($usd, '0.005', 2);

// No permitir cobros cuyo importe convertido sea cero.
if (bccomp($roundedUsd, '0.00', 2) <= 0) {
    throw new InvalidArgumentException(
        'El importe convertido es demasiado pequeno para cobrar en USD.'
    );
}
return $roundedUsd;
    }
}