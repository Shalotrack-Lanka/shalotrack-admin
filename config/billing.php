<?php

/*
| Wholesale billing (invoices, receipts, credit notes).
|
| Checks in code use ABILITIES, never role names. This map says which role has which ability, so giving
| the Finance role access later means editing this file (and, if wanted, switching "approval required" on
| in Billing > Settings), not rewriting controllers.
|
|  billing.view            see own account (ADMIN: everyone's)
|  billing.invoice.issue   issue an invoice from transfers
|  billing.invoice.credit  issue a credit note against an invoice
|  billing.receipt.record  record a payment received
|  billing.receipt.approve approve or reject a pending receipt (never your own)
|  billing.receipt.reverse reverse a valid receipt
|  billing.settings.manage company profile, tax, payment terms, approval switch
|  billing.statement.export download statements / PDFs / CSV
*/
return [
    'abilities' => [
        'ADMIN' => [
            'billing.view',
            'billing.invoice.issue',
            'billing.invoice.credit',
            'billing.receipt.record',
            'billing.receipt.approve',
            'billing.receipt.reverse',
            'billing.settings.manage',
            'billing.statement.export',
        ],
        'DISTRIBUTOR' => ['billing.view', 'billing.statement.export'],
        'DEALER'      => ['billing.view', 'billing.statement.export'],
        'FINANCE'     => [],
    ],

    // Printed before the sequence: SL-INV-2026-000001
    'number_prefix' => env('BILLING_NUMBER_PREFIX', 'SL'),
];
