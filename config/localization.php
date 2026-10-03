<?php

/*
| Phase 19 - locale registry.
|
| English is the only launched language. Hausa, Yoruba, Igbo and Nigerian Pidgin are registered so the
| architecture is ready, but stay `enabled => false` (not selectable, no bundle served) until native-speaker
| terminology review is complete. Enabling a pack = flip `enabled` and add lang/{code}/ui.php.
| Locale only affects UI/resource text. Stored values, codes, money, quantities and timestamps are never
| locale-dependent; user-entered farm records are never translated.
*/
return [
    'default' => env('APP_LOCALE', 'en'),
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    'locales' => [
        'en' => ['name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'enabled' => true, 'status' => 'launched'],
        'ha' => ['name' => 'Hausa', 'native_name' => 'Hausa', 'direction' => 'ltr', 'enabled' => false, 'status' => 'pending_terminology_review'],
        'yo' => ['name' => 'Yoruba', 'native_name' => 'Yorùbá', 'direction' => 'ltr', 'enabled' => false, 'status' => 'pending_terminology_review'],
        'ig' => ['name' => 'Igbo', 'native_name' => 'Igbo', 'direction' => 'ltr', 'enabled' => false, 'status' => 'pending_terminology_review'],
        'pcm' => ['name' => 'Nigerian Pidgin', 'native_name' => 'Naijá', 'direction' => 'ltr', 'enabled' => false, 'status' => 'pending_terminology_review'],
    ],

    /*
    | Contract for every locale: authoritative API numbers and money are dot-decimal strings regardless of
    | display language; clients format for display and send canonical values back.
    */
    'number_input' => ['decimal_separator' => '.', 'thousands_separator' => null, 'input_mode' => 'decimal'],
];
