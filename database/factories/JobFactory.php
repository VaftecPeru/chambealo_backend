<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Job>
 */
class JobFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Job::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->create()->id,
            'user_id' => User::factory()->create()->user_id,
            'transaction_id' => Transaction::factory()->create()->id,
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'action' => $this->faker->randomElement(['payment', 'checkout', 'refund', 'order', 'status']),
            'data' => [
                'amount' => $this->faker->randomFloat(2, 10, 1000),
                'currency' => 'PEN',
                'description' => $this->faker->sentence(),
            ],
            'error_message' => null,
        ];
    }

    /**
     * Indicate that the job is pending.
     */
    public function pending(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the job is processing.
     */
    public function processing(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the job is completed.
     */
    public function completed(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the job failed.
     */
    public function failed(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
