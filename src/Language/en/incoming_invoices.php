<?php

return [
    // List
    'list_title' => 'Incoming invoices',
    'new' => 'New incoming invoice',
    'invoice_number' => 'Invoice number',
    'supplier' => 'Supplier',
    'issue_date' => 'Issued',
    'due_date' => 'Due date',
    'issued_from' => 'Issued from',
    'issued_to' => 'Issued to',
    'amount' => 'Amount',
    'none_found' => 'No invoices match the filter.',

    // Form
    'from_order_prefilled' => 'Line items of order {number} prefilled.',
    'choose_supplier' => 'Choose a supplier…',
    'issue_date_label' => 'Issue date',
    'warehouse_stockin' => 'Stock receipt warehouse',
    'warehouse_hint' => 'If set, the line items are also booked as a stock receipt automatically.',
    'no_auto_receipt' => '— no automatic stock receipt —',
    'create_invoice' => 'Record invoice',

    // Show
    'tax_number' => 'Tax number',
    'receipt_warehouse' => 'Receiving warehouse',
    'product_name' => 'Name',
    'mark_paid' => 'Mark as paid',
    'void' => 'Void',
    'confirm_void' => 'Are you sure you want to void this invoice?',
    'title_prefix' => 'Invoice',

    // Flash / validation
    'required' => 'A supplier and at least one line item are required.',
    'created' => 'Incoming invoice recorded.',
    'created_with_stock' => 'Incoming invoice recorded. Line items also booked as a stock receipt.',
    'marked_paid' => 'Invoice marked as paid.',
    'cancelled' => 'Invoice voided.',
    'deleted' => 'Invoice deleted.',
    'not_payable' => 'Only an unpaid incoming invoice can be marked as paid.',
    'not_cancellable' => 'Only an unpaid incoming invoice can be cancelled.',
    'cancel_has_payments' => 'This invoice has payments: reverse them first, then it can be cancelled.',
    'cancel_shortage' => 'Some of the goods this invoice booked in have already left the warehouse, so the storno cannot book them out; sort out the stock first.',
];
