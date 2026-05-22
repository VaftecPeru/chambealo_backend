<?php

namespace App\Services\WebhookSecurity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * MercadoPagoWebhookVerification
 * 
 * Security verification for Mercado Pago webhooks.
 * 
 * Implements the SAME security level as PayPal from scratch:
 * - X-Signature verification (equivalent to PayPal RSA signature)
 * - Timestamp validation (5 minute window)
 * - Replay attack prevention (using x-request-id)
 * - Rate limiting (per IP)
 * - Payload integrity validation
 * 
 * Mercado Pago uses:
 * - X-Signature: HMAC-SHA256 of request body with access token
 * - X-Request-Id: Unique request identifier for deduplication
 */
class MercadoPagoWebhookVerification implements WebhookSecurityInterface
{
    private const GATEWAY = 'mercadopago';
    private const MAX_TIMESTAMP_MINUTES = 5;
    private const RATE_LIMIT_PER_MINUTE = 60;

    private string $accessToken;
    private array $verificationDetails = [];

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token', '');
        if (!$this->accessToken) {
            throw new \RuntimeException('Mercado Pago access_token not configured');
        }
    }

    /**
     * Verify Mercado Pago X-Signature
     * 
     * Format: X-Signature: sha256=<hex_string>
     * Verification: HMAC-SHA256(body, access_token)
     */
    public function verifySignature(Request $request): bool
    {
        try {
            $signature = $request->header('X-Signature');

            if (!$signature) {
                $this->verificationDetails['signature_verified'] = false;
                $this->verificationDetails['error'] = 'Missing X-Signature header';
                Log::warning('Mercado Pago missing X-Signature header', [
                    'ip' => $request->ip(),
                ]);
                return false;
            }

            // Parse signature format: "sha256=<value>" or just "<value>"
            if (strpos($signature, 'sha256=') === 0) {
                $receivedSignature = substr($signature, 7);
            } else {
                $receivedSignature = $signature;
            }

            // Get raw request body (must be exact same as sent)
            $rawBody = $request->getContent();

            // Calculate expected signature
            $calculatedSignature = hash_hmac(
                'sha256',
                $rawBody,
                $this->accessToken,
                false // return hex string
            );

            // Constant-time comparison
            $isValid = hash_equals($calculatedSignature, $receivedSignature);

            $this->verificationDetails['signature_verified'] = $isValid;
            $this->verificationDetails['signature_method'] = 'x_signature_sha256';
            $this->verificationDetails['payload_hash'] = $calculatedSignature;
            $this->verificationDetails['received_signature'] = substr($receivedSignature, 0, 10) . '...';

            if (!$isValid) {
                Log::warning('Mercado Pago X-Signature verification failed', [
                    'ip' => $request->ip(),
                    'calculated' => substr($calculatedSignature, 0, 10) . '...',
                    'received' => substr($receivedSignature, 0, 10) . '...',
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Mercado Pago signature verification error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['signature_verified'] = false;
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Validate Mercado Pago timestamp
     * Uses X-Request-Timestamp header
     */
    public function validateTimestamp(int $timestamp, int $maxMinutes = self::MAX_TIMESTAMP_MINUTES): bool
    {
        try {
            $now = Carbon::now();
            $webhookTime = Carbon::createFromTimestamp($timestamp);
            $diffMinutes = abs($now->diffInMinutes($webhookTime));

            $isValid = $diffMinutes <= $maxMinutes;

            $this->verificationDetails['timestamp_validated'] = $isValid;
            $this->verificationDetails['webhook_timestamp'] = $timestamp;
            $this->verificationDetails['server_timestamp'] = $now->timestamp;
            $this->verificationDetails['diff_minutes'] = $diffMinutes;

            if (!$isValid) {
                Log::warning('Mercado Pago timestamp outside acceptable window', [
                    'webhook_time' => $webhookTime->toDateTimeString(),
                    'server_time' => $now->toDateTimeString(),
                    'diff_minutes' => $diffMinutes,
                    'max_allowed' => $maxMinutes,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Mercado Pago timestamp validation error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['timestamp_validated'] = false;
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Prevent replay attacks using X-Request-Id
     * Mercado Pago includes unique request ID in each webhook
     */
    public function preventReplayAttack(string $webhookId): bool
    {
        try {
            // Check if this request ID was already processed
            $existingLog = DB::table('payment_logs')
                ->where('replay_prevention_id', $webhookId)
                ->where('gateway', self::GATEWAY)
                ->first();

            if ($existingLog) {
                $this->verificationDetails['replay_detected'] = true;
                $this->verificationDetails['previous_attempt'] = $existingLog->created_at;

                Log::warning('Mercado Pago replay attack detected', [
                    'request_id' => $webhookId,
                    'previous_attempt' => $existingLog->created_at,
                ]);

                return false; // Replay detected - reject
            }

            $this->verificationDetails['replay_detected'] = false;
            $this->verificationDetails['request_id'] = $webhookId;

            return true; // New webhook - allow

        } catch (\Exception $e) {
            Log::error('Mercado Pago replay prevention error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Validate payload integrity using X-Signature
     */
    public function validatePayloadIntegrity(string $payload, string $signature): bool
    {
        try {
            $calculatedSignature = hash_hmac(
                'sha256',
                $payload,
                $this->accessToken,
                false
            );

            $isValid = hash_equals($calculatedSignature, $signature);

            $this->verificationDetails['integrity_verified'] = $isValid;

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Mercado Pago payload integrity validation error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Rate limit check per IP address
     * Allows up to 60 requests per minute per IP
     */
    public function rateLimitCheck(string $ip): bool
    {
        try {
            $oneMinuteAgo = Carbon::now()->subMinute();

            $recentCount = DB::table('payment_logs')
                ->where('gateway', self::GATEWAY)
                ->where('ip_address', $ip)
                ->where('created_at', '>=', $oneMinuteAgo)
                ->count();

            $isUnderLimit = $recentCount < self::RATE_LIMIT_PER_MINUTE;

            $this->verificationDetails['rate_limit_check'] = $isUnderLimit;
            $this->verificationDetails['requests_last_minute'] = $recentCount;
            $this->verificationDetails['rate_limit'] = self::RATE_LIMIT_PER_MINUTE;

            if (!$isUnderLimit) {
                Log::warning('Mercado Pago rate limit exceeded', [
                    'ip' => $ip,
                    'requests_last_minute' => $recentCount,
                    'limit' => self::RATE_LIMIT_PER_MINUTE,
                ]);
            }

            return $isUnderLimit;

        } catch (\Exception $e) {
            Log::error('Mercado Pago rate limit check error', [
                'error' => $e->getMessage(),
            ]);
            // On error, fail open (allow) to prevent blocking legitimate webhooks
            return true;
        }
    }

    /**
     * Get security verification details for logging
     */
    public function getSecurityDetails(): array
    {
        return array_merge($this->verificationDetails, [
            'gateway' => self::GATEWAY,
            'verification_timestamp' => Carbon::now()->timestamp,
        ]);
    }

    /**
     * Get gateway name
     */
    public function getGateway(): string
    {
        return self::GATEWAY;
    }
}
