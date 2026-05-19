<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;

class SubscriptionController extends Controller
{
    public function index()
    {
        // Único método: Desde el contenedor (inyectado por middleware)
        $tenantId = app('tenant_id');

        // Log para debugging (opcional)
        Log::info('Tenant ID from container:', [
            'tenant_id' => $tenantId
        ]);

        // Usar el tenant ID para filtrar las suscripciones correspondientes
        $subscriptions = Subscription::where('user_id', $tenantId)->get();

        return response()->json($subscriptions);
    }
}