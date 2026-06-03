@extends('layouts.app')

@section('title', 'Pago Cancelado - Chambealo')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <!-- Ícono de cancelación -->
        <div class="text-center mb-6">
            <div class="inline-block">
                <div class="text-6xl mb-4">❌</div>
            </div>
        </div>

        <!-- Mensaje Principal -->
        <div class="bg-red-50 border border-red-200 rounded-lg p-8 mb-6">
            <h1 class="text-3xl font-bold text-red-800 text-center mb-2">Pago Cancelado</h1>
            <p class="text-red-700 text-center">Tu transacción no fue completada. Puedes intentarlo nuevamente.</p>
        </div>

        <!-- Razones de Cancelación -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-yellow-800 mb-3">Posibles Razones:</h2>
            <ul class="space-y-2 text-yellow-700">
                <li class="flex items-start">
                    <span class="mr-3">•</span>
                    <span>Cancelaste la transacción</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">•</span>
                    <span>El gateway rechazó la transacción</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">•</span>
                    <span>Fondos insuficientes</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">•</span>
                    <span>Datos incorrectos o problema de seguridad</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3">•</span>
                    <span>Sesión expirada o timeout</span>
                </li>
            </ul>
        </div>

        <!-- Detalles del Intento -->
        @if(isset($order_id) || isset($amount))
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4 text-gray-800">Detalles del Intento</h3>
            
            <div class="space-y-3">
                @if(isset($order_id))
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Orden:</span>
                    <span class="font-semibold text-gray-800">#{{ $order_id }}</span>
                </div>
                @endif

                @if(isset($amount))
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Monto:</span>
                    <span class="font-semibold text-gray-800">{{ $currency ?? 'USD' }} {{ $amount }}</span>
                </div>
                @endif

                @if(isset($gateway))
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Gateway:</span>
                    <span class="font-semibold text-gray-800 capitalize">{{ $gateway }}</span>
                </div>
                @endif

                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Fecha:</span>
                    <span class="text-gray-800">{{ now()->format('d/m/Y H:i:s') }}</span>
                </div>
            </div>
        </div>
        @endif

        <!-- Acciones Recomendadas -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
            <h3 class="font-semibold text-blue-800 mb-4">Acciones Recomendadas:</h3>
            <ol class="space-y-3 text-blue-700">
                <li class="flex items-start">
                    <span class="mr-3 font-semibold">1.</span>
                    <span>Verifica que tus datos de pago sean correctos</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3 font-semibold">2.</span>
                    <span>Asegúrate de tener fondos suficientes en tu cuenta o tarjeta</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3 font-semibold">3.</span>
                    <span>Intenta con otro método de pago</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3 font-semibold">4.</span>
                    <span>Prueba nuevamente después de unos minutos</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-3 font-semibold">5.</span>
                    <span>Si el problema persiste, contacta a nuestro soporte</span>
                </li>
            </ol>
        </div>

        <!-- Botones de Acción -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <a href="{{ route('payments.index') }}" class="text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200">
                Reintentar Pago
            </a>
            <a href="{{ route('dashboard') }}" class="text-center bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200">
                Volver a la Tienda
            </a>
        </div>

        <!-- Información de Seguridad -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <h4 class="font-semibold text-green-800 mb-2">🔒 Tu Información está Segura</h4>
            <p class="text-green-700 text-sm">
                No se realizó ningún cargo a tu cuenta. Si ves una transacción pendiente, contacta a tu banco. 
                Los datos de tu tarjeta nunca fueron almacenados en nuestros servidores.
            </p>
        </div>

        <!-- Soporte -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">¿Necesitas Ayuda?</h3>
            <p class="text-gray-700 mb-4">Si necesitas asistencia para completar tu pago, contáctanos:</p>
            
            <div class="space-y-3">
                <a href="/soporte" class="block text-blue-600 hover:underline font-medium">
                    📞 Centro de Soporte
                </a>
                <a href="mailto:soporte@chambealo.com" class="block text-blue-600 hover:underline font-medium">
                    📧 soporte@chambealo.com
                </a>
                <a href="https://wa.me/mensaje-whatsapp" class="block text-blue-600 hover:underline font-medium">
                    💬 Chat de WhatsApp
                </a>
            </div>

            <div class="mt-4 p-3 bg-white border border-gray-200 rounded">
                <p class="text-sm text-gray-600">
                    <strong>Horario de atención:</strong> Lunes a Viernes 9:00 AM - 6:00 PM
                </p>
            </div>
        </div>

        <!-- Verificación de Transacción -->
        <div class="mt-8 p-4 bg-gray-100 rounded-lg">
            <p class="text-xs text-gray-600">
                <strong>ID de Sesión:</strong> {{ session('transaction_session_id', 'N/A') }}
            </p>
            <p class="text-xs text-gray-600 mt-1">
                Guarda este ID si necesitas consultar con nuestro equipo de soporte.
            </p>
        </div>
    </div>
</div>

<style>
    .space-y-3 > li {
        list-style: none;
    }
</style>
@endsection
