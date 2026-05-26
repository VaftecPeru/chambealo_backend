@extends('layouts.email')

@section('content')
<h1>Payment Confirmation</h1>

<p>Hello {{ $order->user->name ?? 'Valued Customer' }},</p>

<p>Your payment has been successfully processed. Here are the details:</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 10px; font-weight: bold;">Order ID:</td>
        <td style="padding: 10px;">{{ $order->order_id }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 10px; font-weight: bold;">Payment Gateway:</td>
        <td style="padding: 10px;">{{ ucfirst($payment->gateway) }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 10px; font-weight: bold;">Amount:</td>
        <td style="padding: 10px;">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 10px; font-weight: bold;">Status:</td>
        <td style="padding: 10px;">{{ ucfirst($payment->status) }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 10px; font-weight: bold;">Date:</td>
        <td style="padding: 10px;">{{ $payment->created_at->format('Y-m-d H:i:s') }}</td>
    </tr>
</table>

<p>If you have any questions about this payment, please contact our support team.</p>

<p>Thank you for your business!</p>

<p>Best regards,<br>{{ config('app.name') }} Team</p>
@endsection
