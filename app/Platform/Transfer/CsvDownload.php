<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\Csv;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a {@see CsvExport} as a file download.
 *
 * Every batch is read inside ONE repeatable-read transaction, so a table
 * paged 100 rows at a time cannot skip or repeat a row that someone edits
 * mid-download, and the labels resolved alongside stay on the same
 * snapshot (the rule WorkExports set for the project exports). Exports can
 * carry salaries, bank numbers and personal details, so the response is
 * never cached and never sniffed into something a browser would render.
 */
final class CsvDownload
{
    public static function response(CsvExport $export): StreamedResponse
    {
        return response()->stream(static function () use ($export): void {
            // Set only this transaction, not the server/session default.
            $connection = DB::connection();
            if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            DB::transaction(static function () use ($export): void {
                foreach (Csv::chunks($export->headers, ($export->rows)()) as $chunk) {
                    echo $chunk;
                }
            });
        }, 200, [
            'Content-Type' => 'text/csv;charset=utf-8;',
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
