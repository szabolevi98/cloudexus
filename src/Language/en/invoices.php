<?php

return [
    // List
    'list_title' => 'Invoices',
    'new' => 'New invoice',
    'invoice_number' => 'Invoice number',
    'issue_date' => 'Issued',
    'due_date' => 'Due date',
    'issued_from' => 'Issued from',
    'issued_to' => 'Issued to',
    'none_found' => 'No invoices match the filter.',

    // Form
    'from_order_prefilled' => 'Line items of order {number} prefilled.',
    'choose_partner' => 'Choose a partner…',
    'issue_date_label' => 'Issue date',
    'warehouse_stockout' => 'Stock issue from warehouse',
    'warehouse_hint' => 'If set, the invoice line items are also booked as a stock issue automatically.',
    'no_auto_issue' => '— no automatic stock issue —',
    'shipping_cost' => 'Shipping cost ({currency})',
    'payment_cost' => 'Payment cost ({currency})',
    'payment_cost_hint' => '(e.g. cash on delivery)',
    'create_invoice' => 'Issue invoice',

    // Show
    'address' => 'Address',
    'product_name' => 'Name',
    'shipping_cost_row' => 'Shipping cost',
    'payment_cost_row' => 'Payment cost',
    'mark_paid' => 'Mark as paid',
    'void' => 'Issue a cancellation invoice',
    'confirm_void' => 'Issue a cancellation invoice? The original will be cancelled and its goods booked back into stock.',
    'title_prefix' => 'Invoice',

    // Print
    'print_doc_title' => 'INVOICE',
    'stamp_paid' => 'PAID',
    'stamp_cancelled' => 'VOIDED',
    'seller' => 'Seller',
    'buyer' => 'Buyer',
    'tax_number' => 'Tax number',
    'bank_account' => 'Bank account',
    'payment_method' => 'Payment method',
    'payment_transfer' => 'Bank transfer',
    'total_due' => 'Total due',
    'print_footer' => 'This invoice was issued by the Cloudexus business management system.',

    // Flash / validation
    'required' => 'A partner and at least one line item are required.',
    'shortage' => 'Not enough stock for the issue: {items}',
    'shortage_item' => '{sku} (available: {available}, requested: {requested})',
    'created' => 'Invoice issued.',
    'created_with_stock' => 'Invoice issued. Line items also booked as a stock issue.',
    'marked_paid' => 'Invoice marked as paid.',
    'cancelled' => 'Invoice voided.',
    'deleted' => 'Invoice deleted.',

    // VAT, storno, supply date, payment method
    'fulfilment_date' => 'Date of supply',
    'number_on_save' => 'The final number is given when the invoice is saved.',
    'unit_price_net' => 'Net unit price',
    'vat_rate' => 'VAT',
    'net' => 'Net',
    'vat' => 'VAT',
    'gross' => 'Gross',
    'vat_summary' => 'VAT summary',
    'net_total' => 'Net total',
    'vat_total' => 'VAT total',
    'gross_total' => 'Gross total',
    'storno_title' => 'CANCELLATION INVOICE',
    'storno_badge' => 'Cancellation invoice',
    'storno_of' => 'Cancels invoice {number}',
    'stornoed_by' => 'Cancelled by invoice {number}',
    'stornoed' => 'Cancellation invoice issued. The original is cancelled and its goods are back in stock.',
    'not_stornoable' => 'This invoice cannot be cancelled: only an issued invoice that is not yet paid can be.',
    'storno_has_payments' => 'This invoice has payments: reverse them first, then it can be reversed.',
    'not_payable' => 'This invoice cannot be marked as paid.',
    'order_not_invoiceable' => 'Order {number} cannot be invoiced: it has an invoice already, or it is not confirmed.',
    'payment_methods' => [
        'transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'card' => 'Card',
        'cod' => 'Cash on delivery',
    ],

    // CSV
    'csv' => [
        'number' => 'Invoice number',
        'partner' => 'Partner',
        'issue_date' => 'Issued',
        'due_date' => 'Due date',
        'status' => 'Status',
        'total' => 'Total',
    ],
    'csv_status' => [
        'unpaid' => 'awaiting payment',
        'paid' => 'paid',
        'cancelled' => 'voided',
        'storno' => 'cancellation invoice',
    ],
];
