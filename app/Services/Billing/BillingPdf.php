<?php

namespace App\Services\Billing;

use App\Services\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/** Renders documents on demand from their frozen snapshot (nothing is stored). */
class BillingPdf
{
    public function download(string $view, array $data, string $filename, string $label): Response
    {
        Audit::record('billing.document_downloaded', 'document', $filename, $label, [], ['file' => $filename]);

        return Pdf::loadView($view, $data)->setPaper('a4')->download($filename);
    }
}
