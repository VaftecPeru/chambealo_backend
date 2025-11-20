<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class AdvancedThrottle
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function handle(Request $request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $byUserId = false)
    {
        $key = $this->resolveRequestSignature($request);

        if ($byUserId && $request->user()) {
            $key .= '|' . $request->user()->user_id;
        }

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildResponse($key, $maxAttempts);
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts)
        );
    }

    protected function resolveRequestSignature($request)
    {
        return sha1(
            $request->method() .
            '|' . $request->server('SERVER_NAME') .
            '|' . $request->ip() .
            '|' . $request->path()
        );
    }

    protected function buildResponse($key, $maxAttempts)
    {
        $retryAfter = $this->limiter->availableIn($key);

        // Log de intentos sospechosos
        Log::warning('Rate limit exceeded', [
            'key' => $key,
            'max_attempts' => $maxAttempts,
            'retry_after' => $retryAfter,
            'ip' => request()->ip()
        ]);

        return response()->json([
            'error' => 'Too Many Attempts',
            'message' => 'Rate limit exceeded. Please try again in ' . $retryAfter . ' seconds.',
            'retry_after' => $retryAfter
        ], 429);
    }

    protected function addHeaders(Response $response, $maxAttempts, $remainingAttempts)
    {
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ]);

        return $response;
    }

    protected function calculateRemainingAttempts($key, $maxAttempts)
    {
        return $maxAttempts - $this->limiter->attempts($key) + 1;
    }
}
