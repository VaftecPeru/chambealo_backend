<?php

namespace App\Jobs;

use App\Mail\InvoiceMailable;
use App\Models\Order;
use App\Models\Payment;
use App\Utilities\PaymentLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Exception;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Order $order;
    protected ?string $pdfPath;
    protected int $tries = 3;
    protected int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, ?string $pdfPath = null)
    {
        $this->order = $order;
        $this->pdfPath = $pdfPath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $order = $this->order->load(['user', 'plan', 'payments']);
            $pdfPath = $this->pdfPath;

            // Generate PDF if not already provided
            if (!$pdfPath) {
                $pdfPath = $this->generateInvoicePdf($order);
            }

            // Validate PDF exists
            if (!$pdfPath || !Storage::exists($pdfPath)) {
                throw new Exception("Invoice PDF not found: {$pdfPath}");
            }

            // Send email with PDF attachment
            Mail::send(new InvoiceMailable($order, $pdfPath));

            // Update payment record with email_sent_at timestamp
            $order->payments()->update(['email_sent_at' => now()]);

            // Log success
            PaymentLogger::logEmailSend(
                orderId: (string) $order->order_id,
                recipient: $order->user->email ?? 'unknown',
                success: true,
                tenantId: $order->tenant_id,
                userId: $order->user_id
            );

        } catch (Exception $e) {
            $orderId = $this->order->order_id ?? 'unknown';
            $userId = $this->order->user_id ?? null;
            $tenantId = $this->order->tenant_id ?? null;

            // Log failure
            PaymentLogger::logEmailSend(
                orderId: (string) $orderId,
                recipient: $this->order->user->email ?? 'unknown',
                success: false,
                error: $e->getMessage(),
                tenantId: $tenantId,
                userId: $userId
            );

            // Rethrow to trigger queue retry
            throw $e;
        }
    }

    /**
     * Generate invoice PDF using barryvdh/laravel-dompdf.
     */
    protected function generateInvoicePdf(Order $order): string
    {
        try {
            if (!class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
                throw new Exception('barryvdh/laravel-dompdf is not installed');
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoices.pdf', [
                'order' => $order,
                'user' => $order->user,
                'plan' => $order->plan,
            ]);

            $filename = "invoice_{$order->order_id}_{$order->id}.pdf";
            $path = "invoices/{$filename}";

            Storage::put($path, $pdf->output());

            PaymentLogger::logPdfGeneration(
                orderId: (string) $order->order_id,
                filePath: $path,
                tenantId: $order->tenant_id,
                userId: $order->user_id
            );

            return $path;

        } catch (Exception $e) {
            PaymentLogger::logPdfGeneration(
                orderId: (string) $order->order_id,
                error: $e->getMessage(),
                tenantId: $order->tenant_id,
                userId: $order->user_id
            );

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        PaymentLogger::logPaymentError(
            process: 'send_invoice_email_job',
            orderId: (string) $this->order->order_id,
            error: $exception->getMessage(),
            tenantId: $this->order->tenant_id,
            userId: $this->order->user_id
        );
    }
}
