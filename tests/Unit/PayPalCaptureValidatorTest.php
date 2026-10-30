<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Services\Payments\PayPalCaptureValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PayPalCaptureValidatorTest extends TestCase
{
    private PayPalCaptureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new PayPalCaptureValidator();
    }

    private function payment(): Payment
    {
        $payment = new Payment();

        $payment->order_id = 'ORD-123';
        $payment->payment_id = 'PAYPAL-123';
        $payment->amount = '59.90';
        $payment->currency = 'USD';

        return $payment;
    }

    private function paypalOrder(): array
    {
        return [
            'id' => 'PAYPAL-123',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => 'ORD-123',
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => '59.90',
                ],
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-123',
                        'status' => 'COMPLETED',
                        'amount' => [
                            'currency_code' => 'USD',
                            'value' => '59.90',
                        ],
                    ]],
                ],
            ]],
        ];
    }

    public function test_valid_capture_is_accepted(): void
    {
        $result = $this->validator->validate(
            $this->payment(),
            $this->paypalOrder(),
            'CAPTURE-123'
        );

        $this->assertSame('completed', $result['status']);
        $this->assertSame('CAPTURE-123', $result['transaction_id']);
        $this->assertSame('59.90', $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function test_incorrect_amount_is_rejected(): void
    {
        $order = $this->paypalOrder();

        $order['purchase_units'][0]['payments']['captures'][0]
            ['amount']['value'] = '10.00';

        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($this->payment(), $order);
    }

    public function test_incorrect_currency_is_rejected(): void
    {
        $order = $this->paypalOrder();

        $order['purchase_units'][0]['amount']['currency_code'] = 'EUR';

        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($this->payment(), $order);
    }

    public function test_incorrect_order_reference_is_rejected(): void
    {
        $order = $this->paypalOrder();

        $order['purchase_units'][0]['reference_id'] = 'OTHER-ORDER';

        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($this->payment(), $order);
    }

    public function test_uncompleted_capture_is_rejected(): void
    {
        $order = $this->paypalOrder();

        $order['purchase_units'][0]['payments']['captures'][0]
            ['status'] = 'PENDING';

        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($this->payment(), $order);
    }

    public function test_incorrect_capture_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate(
            $this->payment(),
            $this->paypalOrder(),
            'OTHER-CAPTURE'
        );
    }
}
