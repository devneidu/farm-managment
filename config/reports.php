<?php

return [
    // Private disk the generated export files are written to (never a public disk: files are only served by the authorised download endpoint).
    'disk' => env('REPORT_EXPORT_DISK', 'local'),

    // How long a completed export stays downloadable before reports:prune-exports removes the file.
    'retention_days' => (int) env('REPORT_EXPORT_RETENTION_DAYS', 7),

    // Most export requests one user may queue per hour.
    'exports_per_hour' => (int) env('REPORT_EXPORTS_PER_HOUR', 20),
];
