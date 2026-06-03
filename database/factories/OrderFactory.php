<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => $this->faker->unique()->bothify('ORD-#####-####'),
            'user_id' => User::factory()->create()->user_id,
            'total_amount' => $this->faker->randomFloat(2, 10, 5000),
            'taxes' => $this->faker->randomFloat(2, 0, 500),
            'shipping_cost' => $this->faker->randomFloat(2, 0, 100),
            'status' => $this->faker->randomElement(['cart', 'checkout', 'payment_pending', 'paid', 'shipped', 'delivered', 'cancelled']),
            'items' => [
                [
                    'product_id' => $this->faker->uuid(),
                    'quantity' => $this->faker->numberBetween(1, 5),
                    'price' => $this->faker->randomFloat(2, 10, 500),
                ]
            ],
            'shipping_address' => [
                'street' => $this->faker->address(),
                'city' => $this->faker->city(),
                'country' => $this->faker->country(),
                'zip' => $this->faker->postcode(),
            ],
            'billing_address' => [
                'street' => $this->faker->address(),
                'city' => $this->faker->city(),
                'country' => $this->faker->country(),
                'zip' => $this->faker->postcode(),
            ],
            'coupon_code' => null,
            'discount' => 0,
        ];
    }

    /**
     * Indicate that the order is pending.
     */
    public function pending(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'payment_pending',
        ]);
    }

    /**
     * Indicate that the order is in checkout.
     */
    public function checkout(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'checkout',
        ]);
    }

    /**
     * Indicate that the order is completed.
     */
    public function completed(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }
}
