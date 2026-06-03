<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->user_id]);
        
        return [
            'transaction_id' => $this->faker->unique()->bothify('TXN-#####-####'),
            'order_id' => $order->order_id,
            'user_id' => $user->user_id,
            'tenant_id' => $this->faker->lexify('????'),
            'payment_method' => $this->faker->randomElement(['izipay', 'paypal', 'mercadopago']),
            'process' => $this->faker->randomElement(['create_session', 'confirm_payment', 'webhook']),
            'status' => $this->faker->randomElement(['success', 'failed', 'pending']),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'request_payload' => [
                'amount' => 100.00,
                'currency' => 'PEN',
            ],
            'response_payload' => [
                'status' => 'success',
                'message' => 'Payment processed',
            ],
            'provider_transaction_id' => $this->faker->uuid(),
            'webhook_event' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'error_message' => null,
        ];
    }

    /**
     * Indicate that the transaction is pending.
     */
    public function pending(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the transaction is completed.
     */
    public function completed(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the transaction failed.
     */
    public function failed(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Payment was declined',
        ]);
    }
}
