<?php

namespace Tests\Unit;

use App\Services\Payments\PayPalExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PayPalExchangeRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('paypal_fx_usd_pen');

        config()->set('payment.paypal_fx.max_age_minutes', 1440);
    }

    protected function tearDown(): void
    {
        Cache::forget('paypal_fx_usd_pen');

        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeQuote(
        $rate,
        ?string $date = null,
        string $result = 'success'
    ): void {
        Http::preventStrayRequests();

        Http::fake([
            'https://open.er-api.com/v6/latest/USD' =>
                Http::response([
                    'result' => $result,
                    'rates' => [
                        'PEN' => $rate,
                    ],
                    'time_last_update_utc' =>
                        $date ?? now()->toRfc7231String(),
                ], 200),
        ]);
    }

    public function test_accepts_valid_quote(): void
    {
        $this->fakeQuote(3.50);

        $quote = app(PayPalExchangeRate::class)->getQuote();

        $this->assertSame('3.50000000', $quote['rate']);
        $this->assertSame(
            'ExchangeRate-API_Open_Access',
            $quote['source']
        );
        $this->assertNotEmpty($quote['quoted_at']);
    }

    public function test_rejects_missing_rate(): void
    {
        $this->fakeQuote(null);

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_zero_rate(): void
    {
        $this->fakeQuote(0);

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_missing_source(): void
    {
        $this->fakeQuote(
            3.50,
            null,
            'error'
        );

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_expired_quote(): void
    {
        $this->fakeQuote(
            3.50,
            now()->subDays(2)->toRfc7231String()
        );

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_future_quote(): void
    {
        $this->fakeQuote(
            3.50,
            now()->addDays(1)->toRfc7231String()
        );

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }

    public function test_rejects_negative_rate(): void
    {
        $this->fakeQuote(-3.50);

        $this->expectException(RuntimeException::class);

        app(PayPalExchangeRate::class)->getQuote();
    }
}
