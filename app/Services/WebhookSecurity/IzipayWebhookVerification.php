<?php

namespace App\Services\WebhookSecurity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * IzipayWebhookVerification
 * 
 * Security verification for Izipay webhooks.
 * 
 * Implements the same security level as PayPal:
 * - HMAC-SHA256 signature verification (existing)
 * - Timestamp validation (new - 5 minute window)
 * - Replay attack prevention (new - track processed webhooks)
 * - Rate limiting (new - per IP)
 * - Payload integrity validation (improved)
 * 
 * MAINTAINS compatibility with existing HMAC implementation while adding
 * additional security layers used by PayPal.
 */
class IzipayWebhookVerification implements WebhookSecurityInterface
{
    private const GATEWAY = 'izipay';
    private const MAX_TIMESTAMP_MINUTES = 5;
    private const RATE_LIMIT_PER_MINUTE = 60;

    private string $hashKey;
    private array $verificationDetails = [];

    public function __construct()
    {
        $this->hashKey = config('izipay.hash_key', '');
        if (!$this->hashKey) {
            throw new \RuntimeException('Izipay hash_key not configured');
        }
    }

    /**
     * Verify Izipay webhook signature using HMAC-SHA256
     */
    public function verifySignature(Request $request): bool
    {
        try {
            $krAnswer = $request->input('kr-answer');
            $krHash = $request->input('kr-hash');

            if (!$krAnswer || !$krHash) {
                $this->verificationDetails['signature_verified'] = false;
                $this->verificationDetails['error'] = 'Missing kr-answer or kr-hash';
                return false;
            }

            // Calculate expected HMAC-SHA256
            $calculatedHash = hash_hmac('sha256', $krAnswer, $this->hashKey);

            // Constant-time comparison to prevent timing attacks
            $isValid = hash_equals($calculatedHash, $krHash);

            $this->verificationDetails['signature_verified'] = $isValid;
            $this->verificationDetails['signature_method'] = 'hmac_sha256';
            $this->verificationDetails['payload_hash'] = $calculatedHash;
            $this->verificationDetails['received_hash'] = $krHash;

            if (!$isValid) {
                Log::warning('Izipay signature verification failed', [
                    'ip' => $request->ip(),
                    'calculated' => substr($calculatedHash, 0, 10) . '...',
                    'received' => substr($krHash, 0, 10) . '...',
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Izipay signature verification error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['signature_verified'] = false;
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Validate that webhook timestamp is within acceptable window
     * Prevents processing very old or future-dated webhooks
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
                Log::warning('Izipay timestamp outside acceptable window', [
                    'webhook_time' => $webhookTime->toDateTimeString(),
                    'server_time' => $now->toDateTimeString(),
                    'diff_minutes' => $diffMinutes,
                    'max_allowed' => $maxMinutes,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Izipay timestamp validation error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['timestamp_validated'] = false;
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Prevent replay attacks by ensuring webhook hasn't been processed before
     * Uses unique webhook ID (hash of kr-answer)
     */
    public function preventReplayAttack(string $webhookId): bool
    {
        try {
            // Check if this webhook ID was already processed
            $existingLog = DB::table('payment_logs')
                ->where('replay_prevention_id', $webhookId)
                ->where('gateway', self::GATEWAY)
                ->first();

            if ($existingLog) {
                $this->verificationDetails['replay_detected'] = true;
                $this->verificationDetails['previous_attempt'] = $existingLog->created_at;

                Log::warning('Izipay replay attack detected', [
                    'webhook_id' => $webhookId,
                    'previous_attempt' => $existingLog->created_at,
                ]);

                return false; // Replay detected - reject
            }

            $this->verificationDetails['replay_detected'] = false;
            $this->verificationDetails['webhook_id'] = $webhookId;

            return true; // New webhook - allow

        } catch (\Exception $e) {
            Log::error('Izipay replay prevention error', [
                'error' => $e->getMessage(),
            ]);
            $this->verificationDetails['error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Validate payload integrity by re-hashing the kr-answer
     */
    public function validatePayloadIntegrity(string $payload, string $signature): bool
    {
        try {
            $calculatedSignature = hash_hmac('sha256', $payload, $this->hashKey);
            $isValid = hash_equals($calculatedSignature, $signature);

            $this->verificationDetails['integrity_verified'] = $isValid;

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Izipay payload integrity validation error', [
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
                Log::warning('Izipay rate limit exceeded', [
                    'ip' => $ip,
                    'requests_last_minute' => $recentCount,
                    'limit' => self::RATE_LIMIT_PER_MINUTE,
                ]);
            }

            return $isUnderLimit;

        } catch (\Exception $e) {
            Log::error('Izipay rate limit check error', [
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
