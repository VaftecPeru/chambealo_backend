@extends('admin.layouts.app')

@section('title', 'Detalle del Log')

@section('content')
<div class="page-header">
    <div>
        <h1><i class="fas fa-file-contract"></i> Detalle del Log #{{ $log->id }}</h1>
        <p class="text-muted mb-0">Información completa del evento</p>
    </div>
    <div class="btn-group">
        <a href="{{ route('admin.payment-logs.index') }}" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
</div>

<div class="row">
    {{-- Información General --}}
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Información General</h5>
            </div>
            <div class="card-body">
                <table class="detail-table">
                    <tr>
                        <th>ID:</th>
                        <td><strong>#{{ $log->id }}</strong></td>
                    </tr>
                    <tr>
                        <th>Transaction ID:</th>
                        <td>
                            @if($log->transaction_id)
                                <a href="#" class="text-decoration-none">{{ $log->transaction_id }}</a>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Gateway:</th>
                        <td>
                            <span class="badge badge-info">{{ ucfirst($log->gateway ?? 'N/A') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Evento:</th>
                        <td><code>{{ $log->event_type }}</code></td>
                    </tr>
                    <tr>
                        <th>Estado:</th>
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
                    </tr>
                    <tr>
                        <th>Fecha:</th>
                        <td>{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                    </tr>
                    <tr>
                        <th>Webhook ID:</th>
                        <td class="mono">{{ $log->webhook_id ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Intento:</th>
                        <td>
                            @if($log->attempt > 1)
                                <span class="badge badge-warning">{{ $log->attempt }}º intento</span>
                            @else
                                <span>1º intento</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Seguridad --}}
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Seguridad</h5>
            </div>
            <div class="card-body">
                <table class="detail-table">
                    <tr>
                        <th>HTTPS:</th>
                        <td>
                            @if($log->https_verified)
                                <span class="badge badge-success"><i class="fas fa-lock"></i> Verificado</span>
                            @else
                                <span class="badge badge-danger"><i class="fas fa-lock-open"></i> No verificado</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>TLS Versión:</th>
                        <td>{{ $log->tls_version ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Firma:</th>
                        <td>
                            @if($log->signature_verified)
                                <span class="badge badge-success"><i class="fas fa-check"></i> Verificada</span>
                            @else
                                <span class="badge badge-danger"><i class="fas fa-times"></i> Inválida</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Método de Firma:</th>
                        <td><code>{{ $log->signature_method ?? '-' }}</code></td>
                    </tr>
                    <tr>
                        <th>Timestamp:</th>
                        <td>
                            @if($log->timestamp_validated)
                                <span class="badge badge-success"><i class="fas fa-check"></i> Válido</span>
                            @else
                                <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Inválido</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>IP Address:</th>
                        <td class="mono">{{ $log->ip_address ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>User Agent:</th>
                        <td class="mono small">{{ $log->user_agent ?? '-' }}</td>
                    </tr>
                    @if($log->replay_prevention_id)
                    <tr>
                        <th>Replay ID:</th>
                        <td class="mono small">{{ $log->replay_prevention_id }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Request Payload --}}
@if($log->request_payload)
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-arrow-down"></i> Request Payload</h5>
    </div>
    <div class="card-body">
        <pre class="json-view">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
@endif

{{-- Response Payload --}}
@if($log->response_payload)
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-arrow-up"></i> Response Payload</h5>
    </div>
    <div class="card-body">
        <pre class="json-view">{{ json_encode($log->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
@endif

{{-- Headers --}}
@if($log->headers)
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-heading"></i> HTTP Headers</h5>
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Header</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($log->headers as $key => $value)
                <tr>
                    <td><strong>{{ $key }}</strong></td>
                    <td class="mono small">
                        @if(is_array($value))
                            {{ implode(', ', $value) }}
                        @else
                            {{ $value }}
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Detalles de Firma --}}
@if($log->signature_details)
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-pen"></i> Detalles de Firma</h5>
    </div>
    <div class="card-body">
        <pre class="json-view">{{ json_encode($log->signature_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
@endif

{{-- Error Message --}}
@if($log->error_message)
<div class="card mb-4 border-danger">
    <div class="card-header bg-light border-danger">
        <h5 class="mb-0"><i class="fas fa-exclamation-circle text-danger"></i> Error</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-danger mb-0">
            <strong>Error Message:</strong><br>
            <code>{{ $log->error_message }}</code>
        </div>
    </div>
</div>
@endif

{{-- Transacción Asociada --}}
@if($log->transaction)
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> Transacción Asociada</h5>
    </div>
    <div class="card-body">
        <table class="detail-table">
            <tr>
                <th>Transaction ID:</th>
                <td><strong>{{ $log->transaction->id }}</strong></td>
            </tr>
            @if($log->transaction->order_id)
            <tr>
                <th>Order ID:</th>
                <td>{{ $log->transaction->order_id }}</td>
            </tr>
            @endif
            @if($log->transaction->amount)
            <tr>
                <th>Amount:</th>
                <td>{{ $log->transaction->amount }}</td>
            </tr>
            @endif
        </table>
    </div>
</div>
@endif

@endsection

@push('styles')
<style>
    .detail-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .detail-table tr {
        border-bottom: 1px solid #eee;
    }
    
    .detail-table tr:last-child {
        border-bottom: none;
    }
    
    .detail-table th {
        width: 140px;
        padding: 0.75rem;
        background: #f8f9fa;
        font-weight: 600;
        text-align: left;
        vertical-align: top;
    }
    
    .detail-table td {
        padding: 0.75rem;
        vertical-align: top;
    }
    
    .mono {
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        background: #f5f5f5;
        padding: 0.3rem 0.5rem;
        border-radius: 3px;
        word-break: break-all;
    }
    
    .small {
        font-size: 0.85rem;
    }
    
    .json-view {
        background: #f4f4f4;
        padding: 1rem;
        border-radius: 6px;
        overflow-x: auto;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        margin: 0;
        line-height: 1.5;
        color: #333;
    }
    
    .json-view::-webkit-scrollbar {
        height: 6px;
    }
    
    .json-view::-webkit-scrollbar-track {
        background: #eee;
    }
    
    .json-view::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }
    
    .badge {
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        font-weight: 500;
    }
    
    code {
        background: #f5f5f5;
        padding: 0.2rem 0.5rem;
        border-radius: 3px;
        color: #c7254e;
        font-size: 0.95rem;
    }
</style>
@endpush
