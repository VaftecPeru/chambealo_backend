<x-mail::message>
# Invoice #{{ $order->order_id }}

Thank you for your purchase! Your invoice has been attached to this email.

---

## Order Details

| Item | Details |
|------|---------|
| **Order ID** | {{ $order->order_id }} |
| **Order Date** | {{ $order->created_at->format('M d, Y') }} |
| **Status** | {{ ucfirst($order->status) }} |
| **Plan** | {{ $order->plan?->plan_name ?? 'N/A' }} |

---

## Payment Summary

| Description | Amount |
|------------|--------|
| Subtotal | ${{ number_format($order->subtotal, 2) }} |
| Tax | ${{ number_format($order->tax, 2) }} |
| Discount | -${{ number_format($order->discount, 2) }} |
| **Total** | **${{ number_format($order->total, 2) }}** |

---

## Payment Information

@if($payments->count() > 0)
@foreach($payments as $payment)
| Field | Value |
|-------|-------|
| **Payment Method** | {{ ucfirst($payment->payment_method) }} |
| **Payment Status** | {{ ucfirst($payment->status) }} |
| **Amount Paid** | ${{ number_format($payment->amount ?? $order->total, 2) }} |
| **Payment Date** | {{ $payment->created_at->format('M d, Y H:i A') }} |
@endforeach
@else
| Field | Value |
|-------|-------|
| **Status** | Pending |
@endif

---

## Customer Information

| Field | Details |
|-------|---------|
| **Name** | {{ $user->name ?? 'N/A' }} |
| **Email** | {{ $user->email ?? 'N/A' }} |

---

<x-mail::button :url="config('app.url') . '/orders/' . $order->id">
View Order
</x-mail::button>

Thank you for using VAFTEC!

---

**VAFTEC** - Professional Payment Solutions
</x-mail::message>
