<?php

namespace App\Services;

class PaymentFactory
{
    /**
     * Create and return a payment gateway service instance
     *
     * @param string $gateway Gateway identifier (izipay, mercadopago, paypal)
     * @return PaymentServiceInterface
     * @throws \InvalidArgumentException
     */
    public static function make(string $gateway): PaymentServiceInterface
    {
        return match($gateway) {
            'izipay' => app(IzipayService::class),
            'mercadopago' => app(MercadoPagoService::class),
            'paypal' => app(PayPalService::class),
            default => throw new \InvalidArgumentException("Unsupported payment gateway: {$gateway}"),
        };
    }

    /**
     * Get list of available payment gateways
     *
     * @return array
     */
    public static function getAvailableGateways(): array
    {
        return ['izipay', 'mercadopago', 'paypal'];
    }
}
