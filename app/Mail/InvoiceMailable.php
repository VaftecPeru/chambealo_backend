<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected Order $order;
    protected string $pdfPath;

    /**
     * Create a new mailable instance.
     */
    public function __construct(Order $order, string $pdfPath)
    {
        $this->order = $order;
        $this->pdfPath = $pdfPath;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $adminEmail = config('mail.from.address');
        $tenantEmail = $this->order->tenant_id ? $this->getTenantEmail() : null;

        $to = $this->order->user->email ?? 'customer@example.com';
        $cc = array_filter([$adminEmail, $tenantEmail]);

        return new Envelope(
            subject: "Invoice #{$this->order->order_id} - Chambealo",
            to: $to,
            cc: $cc,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'plan' => $this->order->plan,
                'payments' => $this->order->payments,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        $attachments = [];

        if (Storage::exists($this->pdfPath)) {
            $filename = basename($this->pdfPath);
            $attachments[] = Attachment::fromStorage($this->pdfPath)
                ->as($filename)
                ->withMime('application/pdf');
        }

        return $attachments;
    }

    /**
     * Get tenant email if available.
     */
    protected function getTenantEmail(): ?string
    {
        // This assumes tenants are stored with an email field
        // Adjust according to your Tenant model structure
        try {
            $tenantClass = config('app.tenant_model') ?? 'App\\Models\\Tenant';
            if (class_exists($tenantClass)) {
                $tenant = $tenantClass::find($this->order->tenant_id);
                return $tenant?->email;
            }
        } catch (\Exception $e) {
            // Tenant not found or model doesn't exist
        }

        return null;
    }
}
