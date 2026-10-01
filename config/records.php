<?php

return [
    // Private local storage only. File signatures and extensions are both checked; SVG/HTML/scripts are excluded.
    'attachments' => [
        'max_kilobytes' => 10240,
        'max_per_record' => 10,
        'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'csv', 'txt', 'xls', 'xlsx'],
    ],
];
