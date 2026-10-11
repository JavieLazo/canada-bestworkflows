<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\Csv;
use RuntimeException;

/**
 * Reads an uploaded CSV into its header and data rows — the import half of
 * {@see Csv}, which writes every export.
 *
 * Built for the files people actually save from Excel, Google Sheets and
 * Numbers, not only for RFC 4180:
 *  - UTF-8 with or without the BOM Csv writes, UTF-16 ("Unicode Text"), and
 *    Windows-1252, which Excel's plain "CSV (Comma delimited)" still writes
 *    on Windows — so ñ and é survive whichever one was chosen;
 *  - a comma, semicolon or tab delimiter, whichever the header line uses
 *    most (Excel in a comma-decimal locale saves with semicolons);
 *  - quoted cells spanning lines, doubled quotes, CRLF or CR line endings.
 *
 * The apostrophe Csv puts before a cell opening with = + - @ tab or CR (the
 * formula guard) is removed again, so an exported file imports back as it
 * was. Rows are numbered as a spreadsheet numbers them — the header is row
 * 1 and blank rows still count — because that is the number a person looks
 * for when an error names one.
 */
final class CsvReader
{
    public static function parse(string $bytes, int $maxRows): CsvSheet
    {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            throw new RuntimeException('This is an Excel workbook, not a CSV file. In Excel choose File → Save As → "CSV UTF-8", then import that file.');
        }

        $text = self::utf8($bytes);
        if (str_contains($text, "\0")) {
            throw new RuntimeException('This is not a CSV file. Save the sheet as CSV and try again.');
        }

        // One line ending everywhere: fgetcsv then reads a CR-only file, and a
        // CR inside a quoted cell, the same way as the rest.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $delimiter = self::delimiter($text);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('The file could not be read. Choose it again.');
        }
        fwrite($stream, $text);
        rewind($stream);

        $headers = null;
        $rows = [];
        $line = 0;
        try {
            // An empty escape is RFC 4180: only a doubled quote escapes a quote.
            // PHP 8.4 deprecates leaving the argument to its default.
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $line++;
                $cells = array_map(self::cell(...), $cells);
                if (implode('', $cells) === '') {
                    continue;
                }
                if ($headers === null) {
                    $headers = $cells;

                    continue;
                }
                if (count($rows) === $maxRows) {
                    throw new RuntimeException("This file has more than {$maxRows} rows. Import at most {$maxRows} at a time — split the file and import each part.");
                }
                $rows[] = ['line' => $line, 'cells' => $cells];
            }
        } finally {
            fclose($stream);
        }

        if ($headers === null) {
            throw new RuntimeException('The file is empty.');
        }
        // Excel keeps empty trailing columns it once formatted.
        while ($headers !== [] && $headers[array_key_last($headers)] === '') {
            array_pop($headers);
        }

        return new CsvSheet($headers, $rows);
    }

    private static function utf8(string $bytes): string
    {
        $from = null;
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            [$bytes, $from] = [substr($bytes, 2), 'UTF-16LE'];
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            [$bytes, $from] = [substr($bytes, 2), 'UTF-16BE'];
        } elseif (! mb_check_encoding($bytes, 'UTF-8')) {
            $from = 'Windows-1252';
        }

        if ($from === null) {
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_scrub($bytes, 'UTF-8');
        }

        return mb_convert_encoding($bytes, 'UTF-8', $from);
    }

    /** Whichever of comma, semicolon or tab the first non-blank line uses most; comma on a tie. */
    private static function delimiter(string $text): string
    {
        $first = preg_match('/^.*\S.*$/m', $text, $match) === 1 ? $match[0] : '';
        $first = (string) preg_replace('/"[^"]*"/', '', $first);

        $best = ',';
        $count = substr_count($first, ',');
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > $count) {
                [$best, $count] = [$candidate, substr_count($first, $candidate)];
            }
        }

        return $best;
    }

    private static function cell(?string $value): string
    {
        $value = self::trim((string) $value);

        // Undo App\Support\Csv's formula guard: the apostrophe it wrote before
        // = + - @ tab or CR is not part of the value.
        if (preg_match("/^'[=+\\-@\t\r]/", $value) === 1) {
            $value = self::trim(substr($value, 1));
        }

        return $value;
    }

    /** trim() plus the no-break space and stray BOM Excel and Sheets leave in cells. */
    private static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value);
    }
}
