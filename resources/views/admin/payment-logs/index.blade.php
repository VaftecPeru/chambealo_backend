@extends('admin.layouts.app')

@section('title', 'Logs de Pagos')

@section('content')
<div class="page-header">
    <div>
        <h1><i class="fas fa-history"></i> Logs de Pagos y Webhooks</h1>
        <p class="text-muted mb-0">Auditoría completa de transacciones y eventos de seguridad</p>
    </div>
    <div class="btn-group">
        <a href="{{ route('admin.payment-logs.index') }}" class="btn btn-primary btn-sm">
            <i class="fas fa-refresh"></i> Actualizar
        </a>
    </div>
</div>

{{-- Estadísticas --}}
<div class="stats-row">
    <div class="card">
        <h3><i class="fas fa-cube"></i> Total Hoy</h3>
        <div class="number">{{ $stats['total_today'] }}</div>
        <small class="text-muted">Eventos registrados</small>
    </div>
    
    <div class="card">
        <h3><i class="fas fa-exclamation-circle"></i> Fallidos Hoy</h3>
        <div class="number" style="color: #e74c3c;">{{ $stats['failed_today'] }}</div>
        <small class="text-muted">Errores detectados</small>
    </div>
    
    <div class="card">
        <h3><i class="fas fa-shield-alt"></i> Eventos Seguridad</h3>
        <div class="number" style="color: #f39c12;">{{ $stats['security_events'] }}</div>
        <small class="text-muted">Alertas de seguridad</small>
    </div>
    
    @foreach($stats['by_gateway'] as $gateway)
    <div class="card">
        <h3><i class="fas fa-link"></i> {{ ucfirst($gateway->gateway) }}</h3>
        <div class="number">{{ $gateway->total }}</div>
        <small class="text-muted">Total de eventos</small>
    </div>
    @endforeach
</div>

{{-- Filtros --}}
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros de Búsqueda</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="filters">
            <div class="row g-2">
                <div class="col-md-2">
                    <select name="gateway" class="form-select form-select-sm">
                        <option value="">Todos los gateways</option>
                        <option value="paypal" {{ request('gateway') === 'paypal' ? 'selected' : '' }}>PayPal</option>
                        <option value="izipay" {{ request('gateway') === 'izipay' ? 'selected' : '' }}>Izipay</option>
                        <option value="mercadopago" {{ request('gateway') === 'mercadopago' ? 'selected' : '' }}>Mercado Pago</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select name="event_type" class="form-select form-select-sm">
                        <option value="">Todos los eventos</option>
                        <option value="webhook.received" {{ request('event_type') === 'webhook.received' ? 'selected' : '' }}>Webhook Recibido</option>
                        <option value="webhook.verification" {{ request('event_type') === 'webhook.verification' ? 'selected' : '' }}>Verificación</option>
                        <option value="webhook.processed" {{ request('event_type') === 'webhook.processed' ? 'selected' : '' }}>Procesado</option>
                        <option value="payment.completed" {{ request('event_type') === 'payment.completed' ? 'selected' : '' }}>Pago Completado</option>
                        <option value="payment.failed" {{ request('event_type') === 'payment.failed' ? 'selected' : '' }}>Pago Fallido</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos los estados</option>
                        <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Éxito</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Fallido</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pendiente</option>
                        <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Procesando</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}" placeholder="Desde">
                </div>
                
                <div class="col-md-1">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}" placeholder="Hasta">
                </div>
                
                <div class="col-md-2">
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="Buscar webhook_id o ID">
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Tabla de Logs --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th style="width: 80px;">Transacción</th>
                    <th style="width: 120px;">Fecha</th>
                    <th style="width: 100px;">Gateway</th>
                    <th style="width: 150px;">Evento</th>
                    <th style="width: 80px;">Estado</th>
                    <th style="width: 60px;">HTTPS</th>
                    <th style="width: 80px;">Firma</th>
                    <th style="width: 80px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr>
                    <td>
                        <strong>#{{ $log->id }}</strong>
                    </td>
                    <td>
                        @if($log->transaction_id)
                            <a href="#" class="text-decoration-none">{{ $log->transaction_id }}</a>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>
                        <small>{{ $log->created_at->format('d/m/Y H:i:s') }}</small>
                    </td>
                    <td>
                        <span class="badge badge-info">{{ ucfirst($log->gateway ?? 'N/A') }}</span>
                    </td>
                    <td>
                        <code style="font-size: 0.85rem;">{{ $log->event_type }}</code>
                    </td>
                    <td>
                        @if($log->status === 'success')
                            <span class="badge badge-success"><i class="fas fa-check"></i> Éxito</span>
                        @elseif($log->status === 'failed')
                            <span class="badge badge-danger"><i class="fas fa-times"></i> Fallido</span>
                        @elseif($log->status === 'pending')
                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Pendiente</span>
                        @else
                            <span class="badge bg-secondary">{{ ucfirst($log->status) }}</span>
                        @endif
                    </td>
                    <td>
                        @if($log->https_verified)
                            <span class="badge badge-success" title="HTTPS verificado"><i class="fas fa-lock"></i></span>
                        @else
                            <span class="badge badge-danger" title="No HTTPS"><i class="fas fa-lock-open"></i></span>
                        @endif
                    </td>
                    <td>
                        @if($log->signature_verified)
                            <span class="badge badge-success" title="Firma verificada"><i class="fas fa-check"></i></span>
                        @else
                            <span class="badge badge-danger" title="Firma inválida"><i class="fas fa-times"></i></span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('admin.payment-logs.show', $log->id) }}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> Ver
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        No hay logs que coincidan con los criterios de búsqueda
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Paginación --}}
@if($logs->hasPages())
<nav aria-label="Paginación" class="mt-4">
    <ul class="pagination justify-content-center">
        {{-- Link anterior --}}
        @if($logs->onFirstPage())
            <li class="page-item disabled">
                <span class="page-link">&laquo;</span>
            </li>
        @else
            <li class="page-item">
                <a class="page-link" href="{{ $logs->previousPageUrl() }}">&laquo;</a>
            </li>
        @endif

        {{-- Enlaces de números --}}
        @foreach($logs->getUrlRange(1, $logs->lastPage()) as $page => $url)
            @if($page == $logs->currentPage())
                <li class="page-item active">
                    <span class="page-link">{{ $page }}</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                </li>
            @endif
        @endforeach

        {{-- Link siguiente --}}
        @if($logs->hasMorePages())
            <li class="page-item">
                <a class="page-link" href="{{ $logs->nextPageUrl() }}">&raquo;</a>
            </li>
        @else
            <li class="page-item disabled">
                <span class="page-link">&raquo;</span>
            </li>
        @endif
    </ul>
</nav>
@endif

@endsection

@push('styles')
<style>
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .stats-row .card {
        text-align: center;
        padding: 1.5rem;
    }
    
    .stats-row .card h3 {
        margin: 0 0 0.5rem 0;
        font-size: 0.95rem;
        color: #666;
        font-weight: 500;
    }
    
    .stats-row .card .number {
        font-size: 2rem;
        font-weight: bold;
        color: #2c3e50;
        margin: 0.5rem 0;
    }
    
    .stats-row .card small {
        font-size: 0.8rem;
    }
    
    .filters {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .filters select,
    .filters input,
    .filters button {
        border-radius: 6px;
        border: 1px solid #ddd;
    }
    
    .table {
        font-size: 0.95rem;
    }
    
    .table code {
        background: #f5f5f5;
        padding: 0.2rem 0.5rem;
        border-radius: 3px;
        color: #c7254e;
    }
    
    .badge {
        display: inline-block;
        padding: 0.4rem 0.7rem;
    }
    
    .btn-outline-primary {
        color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    .btn-outline-primary:hover {
        background: var(--secondary-color);
        border-color: var(--secondary-color);
        color: white;
    }
</style>
@endpush
