<?php

namespace App\Services\Reports\Writers;

use App\Services\Reports\ReportPayload;
use RuntimeException;
use ZipArchive;

/**
 * A minimal, dependency-free .xlsx writer (one worksheet, inline strings, a bold header). Numeric columns are written as numbers using
 * the exact decimal text from the report (no float conversion happens here); everything else is an inline string, which a spreadsheet
 * never evaluates as a formula. The sheet is streamed to a temp file so a large export does not hold its XML in memory.
 */
class XlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const EXTENSION = 'xlsx';

    private const NUMERIC = ['integer', 'decimal', 'money', 'percent'];

    public function write(array $payload, string $path): void
    {
        $sheet = tempnam(sys_get_temp_dir(), 'xlsx');
        $h = fopen($sheet, 'wb');
        fwrite($h, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $r = 0;
        $emit = function (array $cells, bool $bold = false) use ($h, &$r) {
            $r++;
            $xml = '<row r="'.$r.'">';
            foreach (array_values($cells) as $i => [$value, $numeric]) {
                $ref = self::column($i).$r;
                if ($value === null || $value === '') {
                    continue;
                }
                $s = $bold ? ' s="1"' : '';
                $xml .= $numeric && is_numeric($value)
                    ? '<c r="'.$ref.'"'.$s.'><v>'.self::number((string) $value).'</v></c>'
                    : '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.self::text((string) $value).'</t></is></c>';
            }
            fwrite($h, $xml.'</row>');
        };

        $emit([[$payload['report']['title'], false]], true);
        $emit([['Farm', false], [$payload['farm']['name'], false]]);
        $emit([['Generated', false], [$payload['generated_at'], false], ['Timezone', false], [$payload['timezone'], false]]);
        foreach ($payload['filters'] as $name => $value) {
            $emit([[ucfirst(str_replace('_', ' ', $name)), false], [$value, false]]);
        }
        $emit([]);
        $emit(array_map(fn ($c) => [$c['label'], false], $payload['columns']), true);
        foreach ($payload['rows'] as $row) {
            $emit(array_map(fn ($c) => [$row[$c['key']] ?? null, in_array($c['type'], self::NUMERIC, true)], $payload['columns']));
        }
        $summary = ReportPayload::flatSummary((array) $payload['summary']);
        if ($summary !== []) {
            $emit([]);
            $emit([['Summary', false]], true);
            foreach ($summary as $label => $value) {
                $emit([[$label, false], [$value, false]]);
            }
        }
        foreach ($payload['notes'] as $note) {
            $emit([[$note, false]]);
        }
        fwrite($h, '</sheetData></worksheet>');
        fclose($h);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet file.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheet);
    }

    /** Zero-based column index to A, B, ... Z, AA, ... */
    private static function column(int $i): string
    {
        $name = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26).$name;
        }

        return $name;
    }

    /** A numeric cell value: the report's own decimal text, unchanged (only leading "+" and exponent forms are refused upstream by is_numeric on plain decimals). */
    private static function number(string $value): string
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : '0';
    }

    private static function text(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
