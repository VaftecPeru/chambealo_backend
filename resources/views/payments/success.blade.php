@extends('layouts.app')

@section('title', 'Pago Exitoso - Chambealo')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <!-- Ícono de éxito -->
        <div class="text-center mb-6">
            <div class="inline-block">
                <div class="text-6xl mb-4">✅</div>
            </div>
        </div>

        <!-- Mensaje Principal -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-8 mb-6">
            <h1 class="text-3xl font-bold text-green-800 text-center mb-2">¡Pago Exitoso!</h1>
            <p class="text-green-700 text-center">Tu transacción ha sido procesada correctamente.</p>
        </div>

        <!-- Detalles de la Transacción -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Detalles de la Transacción</h2>
            
            <div class="space-y-4">
                <!-- ID de Referencia -->
                <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                    <span class="text-gray-600">ID de Referencia:</span>
                    <span class="font-mono font-semibold text-gray-800" id="transactionId">
                        {{ $transaction_id ?? 'N/A' }}
                    </span>
                </div>

                <!-- Monto -->
                <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                    <span class="text-gray-600">Monto:</span>
                    <span class="font-semibold text-gray-800 text-lg">
                        {{ $currency ?? 'USD' }} {{ $amount ?? '0.00' }}
                    </span>
                </div>

                <!-- Gateway -->
                <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                    <span class="text-gray-600">Método de Pago:</span>
                    <span class="font-semibold text-gray-800 capitalize">
                        {{ $gateway ?? 'N/A' }}
                    </span>
                </div>

                <!-- Estado -->
                <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                    <span class="text-gray-600">Estado:</span>
                    <span class="inline-block bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-semibold">
                        Completado
                    </span>
                </div>

                <!-- Fecha y Hora -->
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Fecha y Hora:</span>
                    <span class="text-gray-800">{{ now()->format('d/m/Y H:i:s') }}</span>
                </div>
            </div>
        </div>

        <!-- Acciones -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
            <h3 class="font-semibold text-blue-800 mb-4">¿Qué sucede ahora?</h3>
            <ul class="space-y-2 text-blue-700">
                <li class="flex items-start">
                    <span class="mr-3">1.</span>
                    <span>Recibirás un correo de confirmación en breve</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">2.</span>
                    <span>Tu orden será procesada y preparada para envío</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">3.</span>
                    <span>Recibirás notificaciones sobre el estado de tu pedido</span>
                </li>
            </ul>
        </div>

        <!-- Botones de Acción -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <a href="{{ route('dashboard') }}" class="text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200">
                Volver a la Tienda
            </a>
            <a href="{{ route('orders.show', ['order' => $order_id ?? 1]) }}" class="text-center bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200">
                Ver Mi Pedido
            </a>
        </div>

        <!-- Información de Ayuda -->
        <div class="mt-8 bg-gray-50 rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">¿Necesitas Ayuda?</h3>
            <p class="text-gray-700 mb-3">Si tienes dudas sobre tu transacción o tu pedido, puedes:</p>
            <ul class="space-y-2 text-gray-700">
                <li>• <a href="/soporte" class="text-blue-600 hover:underline">Contactar a nuestro equipo de soporte</a></li>
                <li>• <a href="/faq" class="text-blue-600 hover:underline">Consultar nuestras preguntas frecuentes</a></li>
                <li>• <a href="mailto:soporte@chambealo.com" class="text-blue-600 hover:underline">Enviar un correo a soporte@chambealo.com</a></li>
            </ul>
        </div>

        <!-- Opciones de Comparte -->
        <div class="mt-6 text-center">
            <p class="text-gray-600 mb-3">¿Te gustó tu compra? Comparte con tus amigos:</p>
            <div class="flex justify-center gap-4">
                <button onclick="shareOnWhatsApp()" class="bg-green-500 hover:bg-green-600 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    WhatsApp
                </button>
                <button onclick="shareOnTwitter()" class="bg-blue-400 hover:bg-blue-500 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Twitter
                </button>
                <button onclick="shareOnFacebook()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    Facebook
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function shareOnWhatsApp() {
    const text = encodeURIComponent("¡Acabo de realizar una compra en Chambealo! 🛍️");
    window.open(`https://wa.me/?text=${text}`, '_blank');
}

function shareOnTwitter() {
    const text = encodeURIComponent("¡Acabo de realizar una compra en Chambealo! 🛍️ #Chambealo");
    window.open(`https://twitter.com/intent/tweet?text=${text}`, '_blank');
}

function shareOnFacebook() {
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + window.location.href, '_blank');
}

// Copiar ID de referencia al portapapeles
document.getElementById('transactionId').addEventListener('click', function() {
    const text = this.textContent;
    navigator.clipboard.writeText(text);
    alert('ID copiado al portapapeles: ' + text);
});
</script>
@endsection
