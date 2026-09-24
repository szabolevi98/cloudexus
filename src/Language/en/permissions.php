<?php

// Nested along the dots of the key: permissions.keys.stock.view
return [
    'groups' => [
        'overview' => 'Overview',
        'master_data' => 'Master data',
        'inventory' => 'Inventory',
        'sales' => 'Sales',
        'finance' => 'Purchasing and finance',
        'crm' => 'CRM',
        'system' => 'System',
    ],
    'keys' => [
        'dashboard' => [
            'view' => 'View the dashboard',
        ],
        'products' => [
            'view' => 'View products and categories',
            'manage' => 'Edit products',
        ],
        'catalog' => [
            'manage' => 'Manage categories, parameters and units',
        ],
        'partners' => [
            'view' => 'View partners and customer groups',
            'manage' => 'Edit partners',
        ],
        'customer_groups' => [
            'manage' => 'Manage customer groups',
        ],
        'stock' => [
            'view' => 'View warehouses, locations, stock and stocktakings',
            'move' => 'Book stock in, out and between warehouses; barcode collector',
        ],
        'stocktaking' => [
            'manage' => 'Record a stocktaking',
        ],
        'warehouses' => [
            'manage' => 'Manage warehouses and locations',
        ],
        'orders' => [
            'view' => 'View customer orders',
            'manage' => 'Record and cancel customer orders',
        ],
        'invoices' => [
            'view' => 'View, print and export invoices',
            'issue' => 'Issue an invoice',
            'storno' => 'Reverse (storno) an invoice',
        ],
        'purchasing' => [
            'view' => 'View purchase orders and incoming invoices',
            'manage' => 'Record purchase orders and incoming invoices',
        ],
        'finance' => [
            'mark_paid' => 'Mark invoices as paid',
        ],
        'cash' => [
            'view' => 'View cash vouchers',
            'manage' => 'Record and delete cash vouchers',
        ],
        'crm' => [
            'view' => 'View to-dos',
            'manage' => 'Manage to-dos and partner activities',
        ],
        'system' => [
            'users' => 'Manage users',
            'roles' => 'Manage roles and permissions',
            'settings' => 'Company details, currencies, languages',
            'api' => 'API users, log and documentation',
            'audit' => 'View the audit log',
        ],
    ],
];
