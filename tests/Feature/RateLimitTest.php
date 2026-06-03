<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RateLimitTest extends TestCase
{
    public function test_payment_rate_limit()
    {
        $user = User::factory()->create();
        
        // Hacer 5 requests (límite permitido)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($user)
                ->postJson('/api/job/process', [
                    'action' => 'payment',
                    'gateway' => 'mercadopago',
                    'order_id' => 'ORD-123',
                    'amount' => 100,
                    'currency' => 'PEN',
                    'email' => 'test@test.com'
                ]);
            
            $response->assertStatus(201);
        }
        
        // El sexto request debe ser rate limitado
        $response = $this->actingAs($user)
            ->postJson('/api/job/process', [
                'action' => 'payment',
                'gateway' => 'mercadopago',
                'order_id' => 'ORD-123',
                'amount' => 100,
                'currency' => 'PEN',
                'email' => 'test@test.com'
            ]);
        
        $response->assertStatus(429);
        $response->assertJson([
            'success' => false,
            'code' => 'RATE_LIMIT_EXCEEDED'
        ]);
    }
}