<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Order $order;
    protected Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'checkout'
        ]);
        $this->transaction = Transaction::factory()->create([
            'order_id' => $this->order->id
        ]);
    }

    /**
     * Test que un Job se puede crear correctamente
     */
    public function test_job_can_be_created(): void
    {
        $job = Job::create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'transaction_id' => $this->transaction->id,
            'status' => 'pending',
            'action' => 'payment',
            'data' => ['amount' => 100, 'currency' => 'PEN'],
        ]);

        $this->assertDatabaseHas('jobs', [
            'id' => $job->id,
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test relación con Order
     */
    public function test_job_belongs_to_order(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(Order::class, $job->order);
        $this->assertEquals($this->order->id, $job->order->id);
    }

    /**
     * Test relación con User
     */
    public function test_job_belongs_to_user(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(User::class, $job->user);
        $this->assertEquals($this->user->id, $job->user->id);
    }

    /**
     * Test relación con Transaction
     */
    public function test_job_belongs_to_transaction(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'transaction_id' => $this->transaction->id,
        ]);

        $this->assertInstanceOf(Transaction::class, $job->transaction);
        $this->assertEquals($this->transaction->id, $job->transaction->id);
    }

    /**
     * Test método markAsProcessing
     */
    public function test_job_mark_as_processing(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $job->markAsProcessing();
        $this->assertEquals('processing', $job->fresh()->status);
    }

    /**
     * Test método markAsCompleted
     */
    public function test_job_mark_as_completed(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'processing',
        ]);

        $job->markAsCompleted();
        $this->assertEquals('completed', $job->fresh()->status);
    }

    /**
     * Test método markAsFailed
     */
    public function test_job_mark_as_failed(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'processing',
        ]);

        $job->markAsFailed('Test error message');
        $fresh = $job->fresh();
        
        $this->assertEquals('failed', $fresh->status);
        $this->assertEquals('Test error message', $fresh->error_message);
    }

    /**
     * Test scope byStatus
     */
    public function test_job_scope_by_status(): void
    {
        Job::factory(3)->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        Job::factory(2)->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $pending = Job::byStatus('pending')->count();
        $completed = Job::byStatus('completed')->count();

        $this->assertEquals(3, $pending);
        $this->assertEquals(2, $completed);
    }

    /**
     * Test scope forOrder
     */
    public function test_job_scope_for_order(): void
    {
        $order2 = Order::factory()->create(['user_id' => $this->user->id]);

        Job::factory(2)->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
        ]);

        Job::factory()->create([
            'order_id' => $order2->id,
            'user_id' => $this->user->id,
        ]);

        $count = Job::forOrder($this->order->id)->count();
        $this->assertEquals(2, $count);
    }

    /**
     * Test scope forUser
     */
    public function test_job_scope_for_user(): void
    {
        $user2 = User::factory()->create();

        Job::factory(3)->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
        ]);

        Job::factory()->create([
            'order_id' => Order::factory()->create(['user_id' => $user2->id])->id,
            'user_id' => $user2->id,
        ]);

        $count = Job::forUser($this->user->id)->count();
        $this->assertEquals(3, $count);
    }

    /**
     * Test helper methods isPending, isProcessing, isCompleted, isFailed
     */
    public function test_job_status_helper_methods(): void
    {
        $job = Job::factory()->create([
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($job->isPending());
        $this->assertFalse($job->isProcessing());
        $this->assertFalse($job->isCompleted());
        $this->assertFalse($job->isFailed());

        $job->markAsProcessing();
        $this->assertFalse($job->fresh()->isPending());
        $this->assertTrue($job->fresh()->isProcessing());

        $job->markAsCompleted();
        $this->assertTrue($job->fresh()->isCompleted());
        $this->assertFalse($job->fresh()->isFailed());
    }
}
