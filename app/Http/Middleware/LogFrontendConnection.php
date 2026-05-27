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
