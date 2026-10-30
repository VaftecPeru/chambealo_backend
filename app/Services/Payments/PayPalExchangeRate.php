<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use RuntimeException;

class PayPalExchangeRate
{
    public function getQuote(): array
    {
        $rate = config('payment.paypal_fx.pen_per_usd');
        $source = config('payment.paypal_fx.source');
        $quotedAt = config('payment.paypal_fx.quoted_at');
        $maxAge = (int) config(
            'payment.paypal_fx.max_age_minutes',
            1440
        );

        if (
            !is_string($rate) ||
            !preg_match('/^\d+(\.\d{1,8})?$/', $rate) ||
            bccomp($rate, '0', 8) <= 0
        ) {
            throw new RuntimeException(
                'Tipo de cambio PayPal no configurado.'
            );
        }

        if (
            !is_string($source) ||
            trim($source) === ''
        ) {
            throw new RuntimeException(
                'Fuente del tipo de cambio no configurada.'
            );
        }

        if (
            !is_string($quotedAt) ||
            trim($quotedAt) === '' ||
            $maxAge <= 0
        ) {
            throw new RuntimeException(
                'Fecha de cotizacion no valida.'
            );
        }

        try {
            $date = CarbonImmutable::parse($quotedAt);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Fecha de cotizacion incorrecta.'
            );
        }

        $now = CarbonImmutable::now();

        if (
            $date->greaterThan($now) ||
            $date->lessThan(
                $now->subMinutes($maxAge)
            )
        ) {
            throw new RuntimeException(
                'El tipo de cambio esta vencido.'
            );
        }

        return [
            'rate' => $rate,
            'source' => trim($source),
            'quoted_at' => $date->toIso8601String(),
        ];
    }
}
