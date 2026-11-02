<?php

namespace Tests\Unit;

use App\Services\Payments\PenToUsdConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PenToUsdConverterTest extends TestCase
{
    private PenToUsdConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new PenToUsdConverter();
    }

    public function test_converts_pen_to_usd(): void
    {
        $this->assertSame(
            '28.57',
            $this->converter->convert('100.00', '3.50')
        );
    }

    public function test_rounds_to_two_decimals(): void
    {
        $this->assertSame(
            '33.33',
            $this->converter->convert('100.00', '3.00')
        );
    }

    public function test_rejects_zero_exchange_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->converter->convert('100.00', '0');
    }

    public function test_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->converter->convert('-100.00', '3.50');
    }

    public function test_rejects_invalid_exchange_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->converter->convert('100.00', 'abc');
    }

    public function test_rejects_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->converter->convert('0.00', '3.50');
    }

public function test_rejects_amount_that_rounds_to_zero_usd(): void
{
    $this->expectException(InvalidArgumentException::class);

    $this->converter->convert('0.01', '4.00');
}
}
