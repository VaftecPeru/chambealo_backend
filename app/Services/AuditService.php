<?php

namespace App\Services;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public const REDACTED_VALUE = '[REDACTED]';

    /**
     * Substrings (case-insensitive) that identify sensitive keys to redact.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'authorization',
        'cookie',
        'credit_card',
        'card_number',
        'card_pan',
        'security_code',
        'cvv',
        'cvc',
        'iban',
        'bank_account',
        'account_number',
        'routing_number',
        'signature',
        'salt',
        'hash',
    ];

    /**
     * Exact lowercase keys that should be redacted.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_EXACT_KEYS = [
        'pan',
        'pin',
        'cci',
        'ssn',
        'dni',
        'ruc',
        'document_number',
    ];

    public function record(
        string $entityType,
        int $entityId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $performedBy = null,
        ?int $tenantId = null,
        ?Request $request = null,
    ): ?Audit {
        $req = $request ?? (app()->bound('request') ? request() : null);
        $user = $req?->user('api') ?? $req?->user() ?? auth('api')->user() ?? auth()->user();

        $actorId = $performedBy ?? ($user?->user_id !== null ? (int) $user->user_id : null);
        if ($actorId === null) {
            return null;
        }

        $resolvedTenantId = $tenantId;
        if ($resolvedTenantId === null && app()->bound('tenant_id') && app('tenant_id') !== null) {
            $resolvedTenantId = (int) app('tenant_id');
        }
        if ($resolvedTenantId === null && $user?->tenant_id !== null) {
            $resolvedTenantId = (int) $user->tenant_id;
        }

        $userAgent = $req?->userAgent();
        if (is_string($userAgent) && strlen($userAgent) > 512) {
            $userAgent = substr($userAgent, 0, 512);
        }

        return Audit::create([
            'tenant_id' => $resolvedTenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'description' => $description,
            'performed_by' => $actorId,
            'ip_address' => $req?->ip(),
            'user_agent' => $userAgent,
            'timestamp' => now(),
        ]);
    }

    public function recordModel(
        Model $model,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?Request $request = null,
        ?int $performedBy = null,
    ): ?Audit {
        $entityType = class_basename($model);
        $entityId = (int) $model->getKey();
        $modelTenant = $model->getAttribute('tenant_id');
        $tenantId = $modelTenant !== null ? (int) $modelTenant : null;

        return $this->record(
            entityType: $entityType,
            entityId: $entityId,
            action: $action,
            oldValues: $oldValues,
            newValues: $newValues,
            description: $description,
            performedBy: $performedBy,
            tenantId: $tenantId,
            request: $request,
        );
    }

    /**
     * Recursively redact sensitive keys and values before storing or returning audit payloads.
     */
    public function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = self::REDACTED_VALUE;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $sanitized[$key] = $value->format(\DateTimeInterface::ATOM);
                continue;
            }

            if (is_string($value) && $this->looksLikeSecretValue($value)) {
                $sanitized[$key] = self::REDACTED_VALUE;
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        if (in_array($normalized, self::SENSITIVE_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecretValue(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        // Bearer tokens
        if (preg_match('/^Bearer\s+\S+/i', $trimmed) === 1) {
            return true;
        }

        // JWT format: header.payload.signature
        if (preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $trimmed) === 1 && strlen($trimmed) >= 40) {
            return true;
        }

        return false;
    }
}
