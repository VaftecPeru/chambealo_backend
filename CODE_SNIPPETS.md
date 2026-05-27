# 📋 EXACT CODE SNIPPETS - Copy/Paste Reference

## 1️⃣ NEW METHOD to add in `LogTransferService.php`

```php
/**
 * Log frontend connection (nueva funcionalidad)
 * Captura TODAS las conexiones frontend → backend sin interferir
 * 
 * @param Request $request
 * @param Response|null $response
 * @return void
 */
public function logFrontendConnection(Request $request, ?Response $response = null): void
{
    try {
        if (!config('logging.log_frontend_connections')) {
            return;
        }

        $logData = [
            'type' => 'frontend_connection',
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
            'request_id' => $request->header('X-Request-ID', uniqid('req_')),
            'content_type' => $request->header('Content-Type'),
            'response_code' => $response?->getStatusCode() ?? 0,
            'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2),
        ];

        // Anonimizar JWT si existe en Authorization header
        if ($token = $this->extractBearerToken($request)) {
            $logData['token_preview'] = $this->anonymizeJwt($token);
        }

        // Nunca guardar datos sensibles en body
        if ($request->isJson() && !$this->isSensitiveEndpoint($request)) {
            $logData['has_json_payload'] = true;
            $logData['json_keys'] = array_keys($request->json()->all());
        }

        // No bloquear si hay error de BD
        try {
            $this->storeLog($logData, $request);
        } catch (\Exception $e) {
            Log::debug('Frontend connection log failed silently', [
                'error' => $e->getMessage()
            ]);
        }
    } catch (\Exception $e) {
        // Manejar errores silenciosamente sin interrumpir request
        Log::debug('Error logging frontend connection', [
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Extraer token Bearer del header Authorization
 */
private function extractBearerToken(Request $request): ?string
{
    $header = $request->header('Authorization');
    if ($header && str_starts_with($header, 'Bearer ')) {
        return substr($header, 7);
    }
    return null;
}

/**
 * Anonimizar JWT: mostrar primeros 8 y últimos 4 caracteres
 * Ej: "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..." -> "eyJhbGc****UVCJ9..."
 */
private function anonymizeJwt(string $token): string
{
    if (strlen($token) <= 12) {
        return str_repeat('*', strlen($token));
    }
    
    $start = substr($token, 0, 8);
    $end = substr($token, -4);
    $masked = str_repeat('*', strlen($token) - 12);
    
    return "{$start}{$masked}{$end}";
}

/**
 * Determinar si endpoint contiene datos sensibles
 */
private function isSensitiveEndpoint(Request $request): bool
{
    $path = $request->path();
    
    $sensitivePatterns = [
        'login',
        'register',
        'password',
        'token',
        'oauth',
        'payment',
        'webhook',
    ];

    foreach ($sensitivePatterns as $pattern) {
        if (stripos($path, $pattern) !== false) {
            return true;
        }
    }

    return false;
}
```

---

## 2️⃣ COMPLETE MIDDLEWARE FILE: `app/Http/Middleware/LogFrontendConnection.php`

```php
<?php

namespace App\Http\Middleware;

use App\Services\LogTransferService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogFrontendConnection
{
    protected LogTransferService $logService;

    public function __construct(LogTransferService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo loguear si está habilitado en config
        if (!config('logging.log_frontend_connections')) {
            return $next($request);
        }

        // Marcar tiempo de inicio en request (para uso posterior)
        $request->attributes->set('_log_start_time', microtime(true));

        // Ejecutar siguiente middleware/controlador
        $response = $next($request);

        // Loguear conexión con respuesta
        try {
            $this->logService->logFrontendConnection($request, $response);
        } catch (\Exception $e) {
            // No interrumpir request si hay error en logging
            \Illuminate\Support\Facades\Log::debug(
                'LogFrontendConnection middleware error',
                ['error' => $e->getMessage()]
            );
        }

        return $response;
    }
}
```

---

## 3️⃣ LINE TO ADD in `app/Http/Kernel.php`

**Location:** Inside `protected $middleware = [` array, AFTER `HandleCors`

```php
\App\Http\Middleware\LogFrontendConnection::class,
```

**Full context (lines 16-26):**
```php
protected $middleware = [
    // \App\Http\Middleware\TrustHosts::class,
    \App\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    \App\Http\Middleware\LogFrontendConnection::class,  // ← ADD HERE
    \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \App\Http\Middleware\TrimStrings::class,
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    \App\Http\Middleware\IdentifyTenant::class, 
];
```

---

## 4️⃣ VARIABLES TO ADD in `.env`

**Add after `LOG_LEVEL=debug` line:**

```env
LOG_FRONTEND_CONNECTIONS=false
```

**Full context (lines 7-10):**
```env
LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug
LOG_FRONTEND_CONNECTIONS=false
```

---

## 5️⃣ CONFIG TO ADD in `config/logging.php`

**Location:** Inside `return [` at the very beginning

```php
'log_frontend_connections' => env('LOG_FRONTEND_CONNECTIONS', false),
```

**Full context (lines 8-30):**
```php
return [

    /*
    |--------------------------------------------------------------------------
    | Frontend Connection Logging
    |--------------------------------------------------------------------------
    | Enable/disable logging of all frontend connections
    |
    */
    'log_frontend_connections' => env('LOG_FRONTEND_CONNECTIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),
```

---

## 6️⃣ OPTIONAL MIGRATION: `database/migrations/2026_05_27_000000_add_frontend_connection_logging.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Agregar índices optimizados para frontend_connection logs
     */
    public function up(): void
    {
        // Verificar que la tabla existe antes de modificar
        if (Schema::hasTable('payment_logs')) {
            Schema::table('payment_logs', function (Blueprint $table) {
                // Índice compuesto para queries de tipo 'frontend_connection'
                // Permite filtrar por type y período de tiempo eficientemente
                if (!Schema::hasColumn('payment_logs', 'type')) {
                    $table->string('type')->nullable(); // Fallback if column missing
                }
                
                // Este índice ya existe en la migración original (linea 103)
                // Pero agregamos el comentario para documentación
                // $table->index(['type', 'logged_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverting as indices are optional optimizations
    }
};
```

---

## 🎯 SQL Query to Verify Logs

```sql
-- Ver últimas 20 conexiones frontend capturadas
SELECT 
    id,
    JSON_EXTRACT(data, '$.method') as method,
    JSON_EXTRACT(data, '$.path') as path,
    JSON_EXTRACT(data, '$.response_code') as status,
    JSON_EXTRACT(data, '$.response_time_ms') as response_time_ms,
    JSON_EXTRACT(data, '$.token_preview') as token_preview,
    logged_at
FROM payment_logs 
WHERE type = 'frontend_connection'
ORDER BY logged_at DESC 
LIMIT 20;

-- Contar conexiones por endpoint
SELECT 
    JSON_EXTRACT(data, '$.path') as path,
    JSON_EXTRACT(data, '$.method') as method,
    COUNT(*) as total,
    AVG(CAST(JSON_EXTRACT(data, '$.response_time_ms') AS DECIMAL(10,2))) as avg_response_ms
FROM payment_logs 
WHERE type = 'frontend_connection'
GROUP BY path, method
ORDER BY total DESC;

-- Conexiones con errores (status >= 400)
SELECT 
    id,
    JSON_EXTRACT(data, '$.method') as method,
    JSON_EXTRACT(data, '$.path') as path,
    JSON_EXTRACT(data, '$.response_code') as status,
    logged_at
FROM payment_logs 
WHERE type = 'frontend_connection'
AND CAST(JSON_EXTRACT(data, '$.response_code') AS INTEGER) >= 400
ORDER BY logged_at DESC 
LIMIT 20;
```

---

## ✅ Verification Commands

```bash
# 1. Check PHP syntax of new files
php -l app/Services/LogTransferService.php
php -l app/Http/Middleware/LogFrontendConnection.php
php -l app/Http/Kernel.php
php -l config/logging.php

# 2. List created files
ls -la app/Services/LogTransferService.php
ls -la app/Http/Middleware/LogFrontendConnection.php
ls -la database/migrations/2026_05_27_000000_add_frontend_connection_logging.php

# 3. Check that all edits were applied
grep "LogFrontendConnection" app/Http/Kernel.php
grep "LOG_FRONTEND_CONNECTIONS" .env
grep "log_frontend_connections" config/logging.php

# 4. Run Laravel optimization
php artisan config:cache
php artisan optimize
```

---

**All code is ready for deployment! ✅**
