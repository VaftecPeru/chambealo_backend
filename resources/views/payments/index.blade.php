@extends('layouts.app')

@section('title', 'Realizar Pago - Chambealo')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-3xl font-bold mb-6 text-center text-gray-800">Procesador de Pagos</h1>

        @if ($errors->any())
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <h3 class="text-red-800 font-semibold mb-2">Errores de validación:</h3>
                <ul class="list-disc list-inside text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Selector de Gateway -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Paso 1: Seleccionar Método de Pago</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- MercadoPago -->
                <button onclick="selectGateway('mercadopago')" 
                        class="gateway-btn p-4 border-2 border-gray-300 rounded-lg hover:border-blue-500 hover:bg-blue-50 transition duration-200" 
                        data-gateway="mercadopago">
                    <div class="text-2xl mb-2">💳</div>
                    <div class="font-semibold text-gray-800">MercadoPago</div>
                    <div class="text-sm text-gray-600">Tarjeta y billeteras</div>
                </button>

                <!-- Izipay -->
                <button onclick="selectGateway('izipay')" 
                        class="gateway-btn p-4 border-2 border-gray-300 rounded-lg hover:border-purple-500 hover:bg-purple-50 transition duration-200" 
                        data-gateway="izipay">
                    <div class="text-2xl mb-2">🛡️</div>
                    <div class="font-semibold text-gray-800">Izipay</div>
                    <div class="text-sm text-gray-600">Pagos seguros</div>
                </button>

                <!-- PayPal -->
                <button onclick="selectGateway('paypal')" 
                        class="gateway-btn p-4 border-2 border-gray-300 rounded-lg hover:border-yellow-500 hover:bg-yellow-50 transition duration-200" 
                        data-gateway="paypal">
                    <div class="text-2xl mb-2">🌐</div>
                    <div class="font-semibold text-gray-800">PayPal</div>
                    <div class="text-sm text-gray-600">Cuenta PayPal</div>
                </button>
            </div>

            <input type="hidden" id="selectedGateway" name="gateway" value="">
        </div>

        <!-- Formulario de Pago -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Paso 2: Detalles del Pago</h2>
            
            <form id="paymentForm" class="space-y-4">
                @csrf

                <!-- Monto -->
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">
                        Monto a Pagar <span class="text-red-500">*</span>
                    </label>
                    <div class="flex items-center">
                        <span class="text-gray-500 mr-2">$</span>
                        <input type="number" id="amount" name="amount" placeholder="0.00" 
                               step="0.01" min="0.01" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               required>
                    </div>
                </div>

                <!-- Moneda -->
                <div>
                    <label for="currency" class="block text-sm font-medium text-gray-700 mb-1">
                        Moneda <span class="text-red-500">*</span>
                    </label>
                    <select id="currency" name="currency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Seleccionar moneda</option>
                        <option value="USD">USD - Dólar Americano</option>
                        <option value="PEN">PEN - Soles Peruanos</option>
                        <option value="MXN">MXN - Pesos Mexicanos</option>
                        <option value="ARS">ARS - Pesos Argentinos</option>
                    </select>
                </div>

                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                        Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" id="email" name="email" placeholder="correo@ejemplo.com" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           value="{{ auth()->user()->email ?? '' }}" required>
                </div>

                <!-- Descripción -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                        Descripción del Pago
                    </label>
                    <input type="text" id="description" name="description" placeholder="Compra de productos..." 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Información adicional (opcional) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-1">
                            Nombre Completo
                        </label>
                        <input type="text" id="customer_name" name="customer_name" placeholder="Tu nombre" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               value="{{ auth()->user()->name ?? '' }}">
                    </div>

                    <div>
                        <label for="customer_phone" class="block text-sm font-medium text-gray-700 mb-1">
                            Teléfono
                        </label>
                        <input type="tel" id="customer_phone" name="customer_phone" placeholder="+51 999 999 999" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Botón de Envío -->
                <div class="pt-4">
                    <button type="submit" id="submitBtn" 
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200"
                            disabled>
                        <span id="submitText">Selecciona un método de pago</span>
                    </button>
                </div>

                <!-- Loader (oculto inicialmente) -->
                <div id="loadingSpinner" class="hidden text-center">
                    <div class="inline-block">
                        <div class="animate-spin">⏳</div>
                    </div>
                    <p class="text-gray-600 mt-2">Procesando tu pago...</p>
                </div>
            </form>
        </div>

        <!-- Información de Seguridad -->
        <div class="mt-6 bg-green-50 border border-green-200 rounded-lg p-4">
            <h3 class="font-semibold text-green-800 mb-2">🔒 Información de Seguridad</h3>
            <ul class="text-sm text-green-700 space-y-1">
                <li>✓ Todos los pagos están encriptados con HTTPS</li>
                <li>✓ Nunca compartimos tus datos de tarjeta</li>
                <li>✓ Procesados por gateways de pago certificados</li>
                <li>✓ Protegido contra fraude y acceso no autorizado</li>
            </ul>
        </div>
    </div>
</div>

<script>
function selectGateway(gateway) {
    // Actualizar estado del botón seleccionado
    document.querySelectorAll('.gateway-btn').forEach(btn => {
        btn.classList.remove('border-blue-500', 'bg-blue-50', 'border-purple-500', 'bg-purple-50', 'border-yellow-500', 'bg-yellow-50');
        btn.classList.add('border-gray-300');
    });

    const selectedBtn = document.querySelector(`[data-gateway="${gateway}"]`);
    selectedBtn.classList.remove('border-gray-300');
    
    if (gateway === 'mercadopago') {
        selectedBtn.classList.add('border-blue-500', 'bg-blue-50');
    } else if (gateway === 'izipay') {
        selectedBtn.classList.add('border-purple-500', 'bg-purple-50');
    } else if (gateway === 'paypal') {
        selectedBtn.classList.add('border-yellow-500', 'bg-yellow-50');
    }

    // Actualizar campo oculto
    document.getElementById('selectedGateway').value = gateway;

    // Habilitar botón de envío
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitText').textContent = `Pagar con ${gateway}`;
}

document.getElementById('paymentForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const gateway = document.getElementById('selectedGateway').value;
    if (!gateway) {
        alert('Por favor selecciona un método de pago');
        return;
    }

    document.getElementById('loadingSpinner').classList.remove('hidden');
    document.getElementById('submitBtn').disabled = true;

    try {
        const response = await fetch('/api/payment/session', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
            },
            body: JSON.stringify({
                gateway: gateway,
                amount: document.getElementById('amount').value,
                currency: document.getElementById('currency').value,
                email: document.getElementById('email').value,
                description: document.getElementById('description').value,
                customer_name: document.getElementById('customer_name').value,
                customer_phone: document.getElementById('customer_phone').value,
                order_id: @json($order->id ?? 1), // Reemplazar con ID real de orden
            }),
        });

        const data = await response.json();

        if (data.success) {
            // Redirigir a URL de pago según gateway
            if (data.data.redirect_url) {
                window.location.href = data.data.redirect_url;
            } else if (data.data.approve_url) {
                window.location.href = data.data.approve_url;
            }
        } else {
            alert('Error: ' + (data.errors?.error || data.message || 'No se pudo procesar el pago'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al procesar el pago. Por favor intenta de nuevo.');
    } finally {
        document.getElementById('loadingSpinner').classList.add('hidden');
        document.getElementById('submitBtn').disabled = false;
    }
});
</script>

<style>
    .gateway-btn {
        cursor: pointer;
    }

    .animate-spin {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>
@endsection
