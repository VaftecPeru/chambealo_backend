@extends('admin.layouts.app')

@section('content')
<div class="mb-4">
    <a href="#" class="btn btn-secondary">&larr; Volver</a>
    <h2 class="mt-3">Detalle de Log #{{ $log->id }}</h2>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-dark text-white">Información General</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><strong>Gateway:</strong> {{ ucfirst($log->gateway) }}</li>
                <li class="list-group-item"><strong>Status:</strong> {{ $log->status }}</li>
                <li class="list-group-item"><strong>Evento:</strong> {{ $log->event_type }}</li>
                <li class="list-group-item"><strong>Webhook ID:</strong> {{ $log->webhook_id }}</li>
                <li class="list-group-item"><strong>Fecha:</strong> {{ $log->created_at }}</li>
                <li class="list-group-item"><strong>Intentos:</strong> {{ $log->attempt }}</li>
            </ul>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-dark text-white">Seguridad</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><strong>HTTPS Verificado:</strong> {{ $log->https_verified ? 'Sí' : 'No' }}</li>
                <li class="list-group-item"><strong>TLS Version:</strong> {{ $log->tls_version }}</li>
                <li class="list-group-item"><strong>Firma Verificada:</strong> {{ $log->signature_verified ? 'Sí' : 'No' }}</li>
                <li class="list-group-item"><strong>Timestamp Validado:</strong> {{ $log->timestamp_validated ? 'Sí' : 'No' }}</li>
                <li class="list-group-item"><strong>IP Cliente:</strong> {{ $log->ip_address }}</li>
            </ul>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-dark text-white">Payload (Request)</div>
            <div class="card-body bg-light">
                <pre><code>{{ json_encode($log->request_payload, JSON_PRETTY_PRINT) }}</code></pre>
            </div>
        </div>
        
        @if($log->error_message)
        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger text-white">Mensaje de Error</div>
            <div class="card-body">{{ $log->error_message }}</div>
        </div>
        @endif
    </div>
</div>
@endsection
