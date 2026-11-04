<?php

namespace App\Services\Work\Ics;

/** The few RFC 5545 rules the phone feed needs: TEXT escaping, 75-octet line folding (never inside a UTF-8 character) and CRLF line endings. */
final class IcsWriter
{
    public static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);

        return preg_replace('/\r\n|\r|\n/', '\\n', $value) ?? '';
    }

    /** A content line folded so no physical line exceeds 75 octets; continuation lines start with one space. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $length = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $bytes = strlen($char);
            if ($length + $bytes > 75) {
                $out .= "\r\n ";
                $length = 1;
            }
            $out .= $char;
            $length += $bytes;
        }

        return $out;
    }

    /** @param list<string> $lines unfolded content lines */
    public static function document(array $lines): string
    {
        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    /** ISO 8601 duration for a signed number of minutes ("-PT7H", "PT7H30M"). */
    public static function duration(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $m = abs($minutes);
        $h = intdiv($m, 60);
        $r = $m % 60;

        return $sign.'PT'.($h > 0 ? $h.'H' : '').($r > 0 || $h === 0 ? $r.'M' : '');
    }
}
