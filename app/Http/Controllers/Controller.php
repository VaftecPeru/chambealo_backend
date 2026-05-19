<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
    
    /**
     * Obtener el tenant ID actual
     */
    protected function getTenantId()
    {
        return app('tenant_id');
    }
    
    /**
     * Obtener el tenant ID o lanzar excepción
     */
    protected function getTenantIdOrFail()
    {
        $tenantId = $this->getTenantId();
        
        if (!$tenantId) {
            Log::error('Tenant no identificado', [
                'controller' => class_basename($this),
                'user_id' => auth()->id(),
                'ip' => request()->ip()
            ]);
            
            abort(400, 'Tenant not identified');
        }
        
        return $tenantId;
    }
    
    /**
     * Verificar si un modelo pertenece al tenant actual
     */
    protected function belongsToTenant($model)
    {
        $tenantId = $this->getTenantId();
        
        if (!$tenantId || !isset($model->tenant_id)) {
            return false;
        }
        
        return $model->tenant_id == $tenantId;
    }
    
    /**
     * Filtrar query por tenant actual
     */
    protected function scopeTenant($query)
    {
        $tenantId = $this->getTenantId();
        
        if ($tenantId) {
            return $query->where('tenant_id', $tenantId);
        }
        
        return $query;
    }
}