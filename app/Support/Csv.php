<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one CSV builder — the port of `toCsv()` in `src/lib/export.ts`, which
 * every download in the reference shared.
 *
 * It was ported twice: `ProjectsData::toCsv` (the two project exports) put the
 * UTF-8 BOM inside the builder, and `RunWorkspace::toCsv` (the payroll export)
 * left it out and had its `streamDownload` callback echo the BOM instead. Both
 * downloads therefore opened correctly in Excel, but the same three lines of
 * quoting and the same formula guard lived in two files with two cell
 * signatures, and the BOM policy had to be remembered per caller. This is the
 * single copy; the BOM is part of the output, so a caller must never prepend
 * a second one.
 *
 * RFC 4180: every field quoted, quotes doubled, CRLF row endings. The formula
 * guard (export.ts:12-16) prefixes an apostrophe to any STRING cell that opens
 * with `=`, `+`, `-`, `@`, tab or CR, because spreadsheets execute such a cell
 * even when it is quoted, and a leading apostrophe makes Excel read it as
 * text. Numbers are never guarded — a negative amount must stay a number.
 */
final class Csv
{
    /** The UTF-8 byte-order mark Excel needs to read ₱ and é correctly. */
    public const string BOM = "\u{FEFF}";

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    public static function build(array $headers, array $rows): string
    {
        return implode('', iterator_to_array(self::chunks($headers, $rows)));
    }

    /** Same bytes as build(), without retaining every exported row in memory.
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     * @return \Generator<int, string>
     */
    public static function chunks(array $headers, iterable $rows): \Generator
    {
        $cell = static function (mixed $v): string {
            $text = (string) ($v ?? '');

            if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
                $text = "'".$text;
            }

            return '"'.str_replace('"', '""', $text).'"';
        };

        yield self::BOM.implode(',', array_map($cell, $headers));
        foreach ($rows as $row) {
            yield "\r\n".implode(',', array_map($cell, $row));
        }
    }
}
