<?php

namespace App\Services\Reports\Writers;

/** UTF-8 CSV with a byte-order mark (opens correctly in Excel). Table data only: header row then rows. Text cells that a spreadsheet could read as a formula are neutralised. */
class CsvWriter
{
    public const MIME = 'text/csv; charset=UTF-8';

    public const EXTENSION = 'csv';

    public function write(array $payload, string $path): void
    {
        $h = fopen($path, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, array_map(fn ($c) => self::safe($c['label'], 'string'), $payload['columns']), ',', '"', '');
        foreach ($payload['rows'] as $row) {
            $line = [];
            foreach ($payload['columns'] as $c) {
                $line[] = self::safe($row[$c['key']] ?? null, $c['type']);
            }
            fputcsv($h, $line, ',', '"', '');
        }
        fclose($h);
    }

    /** Numbers and dates are written as they are; free text starting with = + - @ (or a control character) is prefixed with an apostrophe. */
    public static function safe(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        if ($type === 'string' && $text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
