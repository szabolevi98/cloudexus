<?php

return [
    // List
    'list_title' => 'Partners',
    'new' => 'New partner',
    'edit_title' => 'Edit partner',
    'search_placeholder' => 'Name, tax number or email…',
    'type' => 'Type',
    'type_customer' => 'Customer',
    'type_supplier' => 'Supplier',
    'type_both' => 'Customer + Supplier',
    'tax_number' => 'Tax number',
    'email' => 'Email',
    'phone' => 'Phone',
    'customer_group' => 'Customer group',
    'none_found' => 'No partners match the filter.',

    // Form
    'customer_group_none' => 'None',
    'addresses_after_save' => 'You can add the partner\'s addresses after saving, on the partner page (multiple addresses are supported, e.g. separate shipping and billing addresses).',

    // Addresses
    'addresses' => 'Addresses',
    'country' => 'Country',
    'postal_code' => 'Postal code',
    'city' => 'City',
    'street' => 'Street, number',
    'address_note_hint' => '(optional, e.g. floor/door)',
    'add_address' => 'Add address',
    'no_addresses' => 'No addresses added yet.',
    'edit_addresses' => 'Edit addresses',
    'confirm_delete_address' => 'Delete this address?',
    'legacy_address_prefix' => 'Previous (unstructured) address',

    // Activities
    'activity_types' => [
        'call' => 'Call',
        'email' => 'Email',
        'meeting' => 'Meeting',
        'note' => 'Note',
        'offer' => 'Quote',
    ],
    'new_entry' => 'New entry',
    'activity_datetime' => 'Date/time',
    'activity_subject' => 'Subject',
    'history_title' => 'Contact history',
    'entries_suffix' => 'entries',
    'confirm_delete_activity' => 'Delete this entry?',
    'recorded_by' => 'Recorded by',
    'no_history' => 'No contact history for this partner yet.',

    // Flash / validation
    'name_required' => 'The partner name is required.',
    'created' => 'Partner created.',
    'updated' => 'Partner updated.',
    'deleted' => 'Partner deleted.',
    'delete_blocked' => 'The partner cannot be deleted: it has orders or invoices. Make it inactive instead.',
    'address_fields_required' => 'City, postal code and street/number are required.',
    'address_added' => 'Address added.',
    'address_updated' => 'Address updated.',
    'address_deleted' => 'Address deleted.',
    'activity_subject_required' => 'The subject is required.',
    'activity_added' => 'Entry added.',
    'activity_deleted' => 'Entry deleted.',

    // CSV export headers
    'csv' => [
        'name' => 'Name',
        'type' => 'Type',
        'tax_number' => 'Tax number',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'active' => 'Active',
    ],
    'csv_type' => [
        'customer' => 'customer',
        'supplier' => 'supplier',
        'both' => 'customer+supplier',
    ],
];
