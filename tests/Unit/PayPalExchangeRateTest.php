<?php

namespace Tests\Unit;

use App\Services\Payments\PayPalExchangeRate;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class PayPalExchangeRateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function configureQuote(
        ?string $rate,
        ?string $source,
        ?string $date
    ): void {
        config()->set('payment.paypal_fx', [
            'pen_per_usd' => $rate,
            'source' => $source,
            'quoted_at' => $date,
            'max_age_minutes' => 1440,
        ]);
    }

    public function test_accepts_valid_quote(): void
    {
        $now = now();

        $this->configureQuote(
            '3.50000000',
            'Proveedor de prueba',
            $now->toIso8601String()
        );

        $quote = app(PayPalExchangeRate::class)->getQuote();

        $this->assertSame('3.50000000', $quote['rate']);
        $this->assertSame('Proveedor de prueba', $quote['source']);
        $this->assertNotEmpty($quote['quoted_at']);
    }

    public function test_rejects_missing_rate(): void
    {
        $this->configureQuote(null, 'Test', now()->toIso8601String());

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_zero_rate(): void
    {
        $this->configureQuote('0', 'Test', now()->toIso8601String());

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_missing_source(): void
    {
        $this->configureQuote('3.50', null, now()->toIso8601String());

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_expired_quote(): void
    {
        $this->configureQuote(
            '3.50',
            'Test',
            now()->subDays(2)->toIso8601String()
        );

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_future_quote(): void
    {
        $this->configureQuote(
            '3.50',
            'Test',
            now()->addDays(1)->toIso8601String()
        );

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_negative_rate(): void
    {
        $this->configureQuote('-3.50', 'Test', now()->toIso8601String());

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }
}
