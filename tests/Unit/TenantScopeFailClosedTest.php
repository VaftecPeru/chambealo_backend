<?php

namespace Tests\Unit;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantScopeFailClosedTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance('tenant_id');
        parent::tearDown();
    }

    public function test_missing_tenant_context_rejects_unscoped_queries(): void
    {
        app()->forgetInstance('tenant_id');
        $controller = new TenantScopeProbe();
        $query = \Mockery::mock(Builder::class);
        $query->shouldNotReceive('where');

        try {
            $controller->filter($query);
            $this->fail('A missing tenant context must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_valid_tenant_context_scopes_the_query(): void
    {
        app()->instance('tenant_id', 42);
        $controller = new TenantScopeProbe();
        $query = \Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('tenant_id', 42)->andReturnSelf();

        $this->assertSame($query, $controller->filter($query));
    }
}

class TenantScopeProbe extends Controller
{
    public function filter($query)
    {
        return $this->scopeTenant($query);
    }
}
