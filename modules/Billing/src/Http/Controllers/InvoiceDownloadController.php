<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers;

use Illuminate\Http\Response;
use Wurk\Billing\Models\CreditNote;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Support\Invoices;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners download their own tax invoices and credit notes; another business's are 404. */
final class InvoiceDownloadController
{
    public function invoice(string $invoice, TenantContext $context, Invoices $invoices): Response
    {
        $model = Invoice::query()->where('tenant_id', $context->id())->findOrFail($invoice);

        return new Response($invoices->pdf($model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$model->number.'.pdf"']);
    }

    public function creditNote(string $note, TenantContext $context, Invoices $invoices): Response
    {
        $model = CreditNote::query()->where('tenant_id', $context->id())->findOrFail($note);
        $invoice = Invoice::query()->findOrFail($model->invoice_id);

        return new Response($invoices->creditNotePdf($model, $invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$model->number.'.pdf"']);
    }
}
