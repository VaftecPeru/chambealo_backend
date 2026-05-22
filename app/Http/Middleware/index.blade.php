@extends('admin.layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Logs de Webhooks y Pagos</h2>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Gateway</th>
                    <th>Evento</th>
                    <th>Status</th>
                    <th>HTTPS</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                <tr>
                    <td>{{ $log->id }}</td>
                    <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td><span class="badge bg-secondary">{{ ucfirst($log->gateway) }}</span></td>
                    <td>{{ $log->event_type }}</td>
                    <td>
                        <span class="badge bg-{{ $log->status === 'success' ? 'success' : ($log->status === 'failed' ? 'danger' : 'warning') }}">
                            {{ $log->status }}
                        </span>
                    </td>
                    <td>
                        {!! $log->https_verified ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-danger">No</span>' !!}
                    </td>
                    <td><a href="#" class="btn btn-sm btn-info">Ver Detalle</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection