<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyPaymentAuthenticationTest extends TestCase
{
    public function test_legacy_process_payment_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/process-payment', [
            'transaction_amount' => 10.00,
            'token' => 'not-a-real-payment-token',
            'payment_method_id' => 'test',
            'payer' => ['email' => 'example@example.test'],
            'installments' => 1,
        ])->assertUnauthorized();
    }
}
