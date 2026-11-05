<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayPalExchangeRate
{
    public function getQuote(): array
    {
        return Cache::remember(
            'paypal_fx_usd_pen',
            now()->addMinutes(60),
            function () {
                try {
                    $response = Http::timeout(15)
                        ->get('https://open.er-api.com/v6/latest/USD');

                    if (!$response->successful() ||
                        $response->json('result') !== 'success') {
                        throw new RuntimeException(
                            'No se pudo consultar el tipo de cambio.'
                        );
                    }

                    $rate = $response->json('rates.PEN');
                    $updated = $response->json('time_last_update_utc');

                    if (!is_numeric($rate) ||
                        !is_finite((float) $rate) ||
                        (float) $rate <= 0 ||
                        !is_string($updated)) {
                        throw new RuntimeException(
                            'La API devolvio una cotizacion invalida.'
                        );
                    }

                    $quotedAt = CarbonImmutable::parse($updated);
                    $now = CarbonImmutable::now();

                    $maxAge = (int) config(
                        'payment.paypal_fx.max_age_minutes',
                        1440
                    );

                    if ($maxAge <= 0 ||
                        $quotedAt->greaterThan($now) ||
                        $quotedAt->lessThan(
                            $now->subMinutes($maxAge)
                        )) {
                        throw new RuntimeException(
                            'La cotizacion recibida esta vencida.'
                        );
                    }

                    return [
                        'rate' => number_format(
                            (float) $rate,
                            8,
                            '.',
                            ''
                        ),
                        'source' => 'ExchangeRate-API_Open_Access',
                        'quoted_at' => $quotedAt->toIso8601String(),
                    ];
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        'No se pudo obtener una cotizacion vigente.',
                        0,
                        $e
                    );
                }
            }
        );
    }
}
