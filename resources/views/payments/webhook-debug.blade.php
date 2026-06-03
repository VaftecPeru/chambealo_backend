@extends('layouts.app')

@section('title', 'Debug Webhooks - Chambealo (DESARROLLO)')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <!-- Advertencia de desarrollo -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <span class="text-2xl mr-3">⚠️</span>
                <div>
                    <h2 class="font-bold text-yellow-800">SOLO PARA DESARROLLO</h2>
                    <p class="text-yellow-700">Esta página está disponible solo en ambiente de desarrollo. Oculta información sensible de webhooks.</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Panel de Control Izquierdo -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6 sticky top-4">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">Herramientas Debug</h2>

                    <!-- Seleccionar Gateway -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Gateway</label>
                        <select id="gatewaySelect" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">Todos</option>
                            <option value="mercadopago">MercadoPago</option>
                            <option value="izipay">Izipay</option>
                            <option value="paypal">PayPal</option>
                        </select>
                    </div>

                    <!-- Seleccionar Estado -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
                        <select id="statusSelect" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">Todos</option>
                            <option value="success">Exitosos</option>
                            <option value="failed">Fallidos</option>
                            <option value="pending">Pendientes</option>
                        </select>
                    </div>

                    <!-- Botón Refrescar -->
                    <button onclick="refreshLogs()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg mb-3 transition duration-200">
                        🔄 Refrescar
                    </button>

                    <!-- Botón Limpiar -->
                    <button onclick="clearLogs()" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                        🗑️ Limpiar Logs
                    </button>
                </div>
            </div>

            <!-- Panel de Logs Derecho -->
            <div class="lg:col-span-2">
                <!-- Estadísticas -->
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="text-2xl font-bold text-green-600" id="successCount">0</div>
                        <div class="text-sm text-green-700">Exitosos</div>
                    </div>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="text-2xl font-bold text-red-600" id="failedCount">0</div>
                        <div class="text-sm text-red-700">Fallidos</div>
                    </div>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="text-2xl font-bold text-yellow-600" id="pendingCount">0</div>
                        <div class="text-sm text-yellow-700">Pendientes</div>
                    </div>
                </div>

                <!-- Tabla de Logs -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="font-bold text-gray-800">Webhooks Recientes</h3>
                    </div>

                    <div id="logsContainer" class="divide-y max-h-96 overflow-y-auto">
                        <div class="p-6 text-center text-gray-500">
                            Cargando webhooks...
                        </div>
                    </div>
                </div>

                <!-- Detalles del Webhook Seleccionado -->
                <div id="detailsPanel" class="mt-6 bg-white rounded-lg shadow-md p-6 hidden">
                    <h3 class="font-bold text-gray-800 mb-4">Detalles del Webhook</h3>
                    <pre id="webhookDetails" class="bg-gray-100 p-4 rounded text-xs overflow-x-auto"></pre>
                </div>
            </div>
        </div>

        <!-- Sección de Prueba Manual -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold mb-4 text-gray-800">Prueba Manual de Webhook</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Selector de Gateway para Prueba -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Gateway a Probar</label>
                    <select id="testGateway" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">Seleccionar</option>
                        <option value="mercadopago">MercadoPago</option>
                        <option value="izipay">Izipay</option>
                        <option value="paypal">PayPal</option>
                    </select>
                </div>

                <!-- Tipo de Evento -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Evento</label>
                    <select id="eventType" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="success">Pago Exitoso</option>
                        <option value="failed">Pago Fallido</option>
                        <option value="pending">Pago Pendiente</option>
                        <option value="refund">Reembolso</option>
                    </select>
                </div>
            </div>

            <!-- Payload -->
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Payload JSON (Opcional)</label>
                <textarea id="payloadInput" class="w-full h-32 px-3 py-2 border border-gray-300 rounded-lg font-mono text-xs" placeholder='{"data": {...}}' ></textarea>
            </div>

            <!-- Botón Enviar -->
            <div class="mt-4 flex gap-3">
                <button onclick="sendTestWebhook()" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                    📤 Enviar Webhook Test
                </button>
                <button onclick="generateSamplePayload()" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                    📋 Generar Ejemplo
                </button>
            </div>

            <!-- Respuesta -->
            <div id="responsePanel" class="mt-4 hidden">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h4 class="font-bold text-blue-800 mb-2">Respuesta del Webhook:</h4>
                    <pre id="responseContent" class="bg-white p-3 rounded text-xs overflow-x-auto border border-blue-200"></pre>
                </div>
            </div>
        </div>

        <!-- Información de Configuración -->
        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <h3 class="font-bold text-blue-800 mb-4">Configuración Actual</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <div class="text-sm text-blue-700">Ambiente</div>
                    <div class="font-mono font-bold text-blue-900">{{ app()->environment() }}</div>
                </div>
                <div>
                    <div class="text-sm text-blue-700">Webhook URL Base</div>
                    <div class="font-mono text-xs text-blue-900 break-all">{{ url('/api/payment/webhook') }}</div>
                </div>
                <div>
                    <div class="text-sm text-blue-700">Timestamp</div>
                    <div class="font-mono font-bold text-blue-900">{{ now()->timestamp }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function refreshLogs() {
    const gateway = document.getElementById('gatewaySelect').value;
    const status = document.getElementById('statusSelect').value;

    // Simular carga de logs desde API
    fetch(`/api/admin/webhook-logs?gateway=${gateway}&status=${status}`)
        .then(r => r.json())
        .then(data => {
            updateLogsDisplay(data);
        })
        .catch(err => console.error('Error loading logs:', err));
}

function updateLogsDisplay(data) {
    const container = document.getElementById('logsContainer');
    
    if (!data.logs || data.logs.length === 0) {
        container.innerHTML = '<div class="p-6 text-center text-gray-500">No hay webhooks registrados</div>';
        return;
    }

    container.innerHTML = data.logs.map(log => `
        <div class="p-4 hover:bg-gray-50 cursor-pointer" onclick="showDetails('${log.id}')">
            <div class="flex justify-between items-start">
                <div>
                    <div class="font-semibold text-gray-800">${log.gateway}</div>
                    <div class="text-sm text-gray-600">${log.timestamp}</div>
                </div>
                <span class="px-2 py-1 rounded text-xs font-semibold
                    ${log.status === 'success' ? 'bg-green-100 text-green-700' : 
                      log.status === 'failed' ? 'bg-red-100 text-red-700' :
                      'bg-yellow-100 text-yellow-700'}">
                    ${log.status}
                </span>
            </div>
            <div class="text-xs text-gray-500 mt-1">IP: ${log.ip}</div>
        </div>
    `).join('');

    // Actualizar estadísticas
    const stats = {
        success: data.logs.filter(l => l.status === 'success').length,
        failed: data.logs.filter(l => l.status === 'failed').length,
        pending: data.logs.filter(l => l.status === 'pending').length,
    };

    document.getElementById('successCount').textContent = stats.success;
    document.getElementById('failedCount').textContent = stats.failed;
    document.getElementById('pendingCount').textContent = stats.pending;
}

function showDetails(id) {
    // Mostrar detalles del webhook
    const panel = document.getElementById('detailsPanel');
    panel.classList.remove('hidden');
    // Cargar detalles desde API
    fetch(`/api/admin/webhook-logs/${id}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('webhookDetails').textContent = JSON.stringify(data, null, 2);
        });
}

function clearLogs() {
    if (confirm('¿Estás seguro de que quieres eliminar todos los logs?')) {
        fetch('/api/admin/webhook-logs/clear', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                alert('Logs eliminados');
                refreshLogs();
            });
    }
}

function generateSamplePayload() {
    const gateway = document.getElementById('testGateway').value;
    const eventType = document.getElementById('eventType').value;

    const payloads = {
        mercadopago: {
            success: '{"action":"payment.created","data":{"id":123456789,"status":"approved"}}'
        },
        izipay: {
            success: '{"kr-answer":"{\\"transactions\\":[{\\"uuid\\":\\"test-uuid\\"}],\\"orderStatus\\":\\"PAID\\"}"}'
        },
        paypal: {
            success: '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"capture-id-123"}}'
        }
    };

    if (payloads[gateway]) {
        document.getElementById('payloadInput').value = payloads[gateway][eventType] || '';
    }
}

function sendTestWebhook() {
    const gateway = document.getElementById('testGateway').value;
    if (!gateway) {
        alert('Selecciona un gateway');
        return;
    }

    const payload = document.getElementById('payloadInput').value;
    const responsePanel = document.getElementById('responsePanel');

    fetch(`/api/payment/webhook/${gateway}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Request-Id': 'test-' + Date.now(),
        },
        body: payload || '{}'
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('responseContent').textContent = JSON.stringify(data, null, 2);
        responsePanel.classList.remove('hidden');
    })
    .catch(err => {
        document.getElementById('responseContent').textContent = 'Error: ' + err.message;
        responsePanel.classList.remove('hidden');
    });
}

// Cargar logs al iniciar
document.addEventListener('DOMContentLoaded', refreshLogs);
</script>

<style>
    .hidden {
        display: none;
    }
</style>
@endsection
