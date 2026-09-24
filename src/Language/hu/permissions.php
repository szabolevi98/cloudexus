<?php

// A kulcsok pontjai mentén egymásba ágyazva: permissions.keys.stock.view
return [
    'groups' => [
        'overview' => 'Áttekintés',
        'master_data' => 'Törzsadatok',
        'inventory' => 'Készletkezelés',
        'sales' => 'Értékesítés',
        'finance' => 'Beszerzés és pénzügy',
        'crm' => 'CRM',
        'system' => 'Rendszer',
    ],
    'keys' => [
        'dashboard' => [
            'view' => 'Vezérlőpult megtekintése',
        ],
        'products' => [
            'view' => 'Termékek és kategóriák megtekintése',
            'manage' => 'Termékek szerkesztése',
        ],
        'catalog' => [
            'manage' => 'Kategóriák, paraméterek, mennyiségi egységek kezelése',
        ],
        'partners' => [
            'view' => 'Partnerek és vevőcsoportok megtekintése',
            'manage' => 'Partnerek szerkesztése',
        ],
        'customer_groups' => [
            'manage' => 'Vevőcsoportok kezelése',
        ],
        'pricing' => [
            'manage' => 'Árszabályok kezelése',
        ],
        'stock' => [
            'view' => 'Raktárak, tárhelyek, készlet és leltárak megtekintése',
            'move' => 'Bevét, kiadás, átadás, vonalkód gyűjtő',
        ],
        'stocktaking' => [
            'manage' => 'Leltár rögzítése',
        ],
        'warehouses' => [
            'manage' => 'Raktárak és tárhelyek kezelése',
        ],
        'orders' => [
            'view' => 'Vevői rendelések megtekintése',
            'manage' => 'Vevői rendelések rögzítése, lemondása',
        ],
        'invoices' => [
            'view' => 'Számlák megtekintése, nyomtatása, exportja',
            'issue' => 'Számla kiállítása',
            'storno' => 'Számla sztornózása',
        ],
        'purchasing' => [
            'view' => 'Szállítói rendelések és bejövő számlák megtekintése',
            'manage' => 'Szállítói rendelések és bejövő számlák rögzítése',
        ],
        'finance' => [
            'mark_paid' => 'Számlák kifizetettnek jelölése',
        ],
        'cash' => [
            'view' => 'Pénztárbizonylatok megtekintése',
            'manage' => 'Pénztárbizonylatok rögzítése, törlése',
        ],
        'crm' => [
            'view' => 'Teendők megtekintése',
            'manage' => 'Teendők és partner-aktivitások kezelése',
        ],
        'system' => [
            'users' => 'Felhasználók kezelése',
            'roles' => 'Szerepkörök és jogosultságok kezelése',
            'settings' => 'Cégadatok, pénznemek, nyelvek',
            'api' => 'API felhasználók, napló és dokumentáció',
            'audit' => 'Audit napló megtekintése',
        ],
    ],
];
