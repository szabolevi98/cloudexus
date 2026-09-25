<?php

return [
    // List
    'list_title' => 'Partnerek',
    'new' => 'Új partner',
    'edit_title' => 'Partner szerkesztése',
    'search_placeholder' => 'Név, adószám vagy e-mail…',
    'type' => 'Típus',
    'type_customer' => 'Vevő',
    'type_supplier' => 'Szállító',
    'type_both' => 'Vevő + Szállító',
    'tax_number' => 'Adószám',
    'email' => 'E-mail',
    'phone' => 'Telefon',
    'customer_group' => 'Vevőcsoport',
    'none_found' => 'Nincs a szűrésnek megfelelő partner.',

    // Form
    'customer_group_none' => 'Nincs',
    'addresses_after_save' => 'A partner címeit a mentés után, a partner adatlapján tudod hozzáadni (több cím is felvehető, pl. külön szállítási és számlázási cím).',

    // Addresses
    'addresses' => 'Címek',
    'country' => 'Ország',
    'postal_code' => 'Irányítószám',
    'city' => 'Város',
    'street' => 'Utca, házszám',
    'address_note_hint' => '(opcionális, pl. emelet/ajtó)',
    'add_address' => 'Cím hozzáadása',
    'no_addresses' => 'Még nincs cím felvéve.',
    'edit_addresses' => 'Címek szerkesztése',
    'confirm_delete_address' => 'Törlöd a címet?',
    'legacy_address_prefix' => 'Korábbi (nem szerkezetes) cím',

    // Activities
    'activity_types' => [
        'call' => 'Hívás',
        'email' => 'E-mail',
        'meeting' => 'Találkozó',
        'note' => 'Jegyzet',
        'offer' => 'Ajánlat',
    ],
    'new_entry' => 'Új bejegyzés',
    'activity_datetime' => 'Időpont',
    'activity_subject' => 'Tárgy',
    'history_title' => 'Kapcsolattörténet',
    'entries_suffix' => 'bejegyzés',
    'confirm_delete_activity' => 'Törlöd a bejegyzést?',
    'recorded_by' => 'Rögzítette',
    'no_history' => 'Még nincs kapcsolattörténet ehhez a partnerhez.',

    // Flash / validation
    'name_required' => 'A partner nevének megadása kötelező.',
    'created' => 'Partner létrehozva.',
    'updated' => 'Partner frissítve.',
    'deleted' => 'Partner törölve.',
    'delete_blocked' => 'A partner nem törölhető, mert tartozik hozzá rendelés vagy számla. Állítsd inkább inaktívra.',
    'address_fields_required' => 'A város, az irányítószám és az utca-házszám megadása kötelező.',
    'address_added' => 'Cím hozzáadva.',
    'address_updated' => 'Cím frissítve.',
    'address_deleted' => 'Cím törölve.',
    'activity_subject_required' => 'A tárgy megadása kötelező.',
    'activity_added' => 'Bejegyzés hozzáadva.',
    'activity_deleted' => 'Bejegyzés törölve.',

    // CSV export headers
    'csv' => [
        'name' => 'Név',
        'type' => 'Típus',
        'tax_number' => 'Adószám',
        'email' => 'E-mail',
        'phone' => 'Telefon',
        'address' => 'Cím',
        'active' => 'Aktív',
    ],
    'csv_type' => [
        'customer' => 'vevő',
        'supplier' => 'szállító',
        'both' => 'vevő+szállító',
    ],
];
