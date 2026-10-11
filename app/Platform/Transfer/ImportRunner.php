<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\Access;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs a CSV through a {@see TableImport}, all or nothing.
 *
 * Both passes do the same work: every row goes through the owner's own
 * create/update command, each inside a savepoint of ONE transaction, so a
 * refused row is undone on its own and the next row still runs — the
 * preview can list every error at once. A preview then rolls the whole
 * transaction back. A commit keeps it only when no row failed: a file with
 * any invalid row saves nothing.
 *
 * Validating by running the real commands, instead of a second copy of
 * their rules, is safe because nothing they do escapes the rollback:
 * AuditService and Realtime wait for the outermost commit, notification
 * rows are written inside the transaction, and a rolled-back transaction
 * drops its after-commit callbacks. An owner command that starts writing
 * files or calling out before commit must not be offered for import.
 *
 * A deadlock or lost connection inside a savepoint cannot be undone row by
 * row (MariaDB has already rolled the whole transaction back), so it ends
 * the run and the actor is asked to retry. Nothing was kept either way.
 */
final class ImportRunner
{
    public function preview(Access $actor, TableImport $import, string $context, string $bytes): ImportReport
    {
        return $this->run($actor, $import, $context, $bytes, false);
    }

    public function commit(Access $actor, TableImport $import, string $context, string $bytes): ImportReport
    {
        return $this->run($actor, $import, $context, $bytes, true);
    }

    public static function maxFileBytes(): int
    {
        return max(1, (int) config('copems.imports.max_file_bytes'));
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('copems.imports.max_rows'));
    }

    /** `2 MB`, `750 KB` — the cap as the dialog and its refusals print it. */
    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1_000_000
            ? rtrim(rtrim(number_format($bytes / 1_000_000, 1), '0'), '.').' MB'
            : (int) ceil($bytes / 1_000).' KB';
    }

    private function run(Access $actor, TableImport $import, string $context, string $bytes, bool $keep): ImportReport
    {
        abort_unless($import->allows($actor, $context), 403);

        if (strlen($bytes) > self::maxFileBytes()) {
            return ImportReport::refused(0, 'This file is larger than '.self::humanSize(self::maxFileBytes()).'. Split it and import each part.');
        }
        try {
            $sheet = CsvReader::parse($bytes, self::maxRows());
        } catch (RuntimeException $e) {
            return ImportReport::refused(0, $e->getMessage());
        }

        $layout = $import->layout($actor, $context);
        [$positions, $problems, $ignored] = self::columns($sheet->headers, $layout->columns);
        if ($problems !== []) {
            return ImportReport::refused(1, ...$problems);
        }
        if ($sheet->rows === []) {
            return ImportReport::refused(0, 'The file has a header row but no data rows.');
        }

        [$order, $assumed] = self::dateOrder($sheet);
        $notices = [];
        if ($ignored !== []) {
            $notices[] = 'Not imported (read-only or unknown): '.implode(', ', $ignored).'.';
        }
        if ($assumed) {
            $notices[] = 'Dates such as 4/9/2026 were read month first, as Excel writes them: 4/9/2026 is 9 Apr 2026. If your file puts the day first, write dates like 2026-04-09.';
        }

        $counts = [ImportOutcome::CREATED->value => 0, ImportOutcome::UPDATED->value => 0, ImportOutcome::UNCHANGED->value => 0];
        $errors = [];
        $failed = 0;
        $width = count($sheet->headers);
        $connection = DB::connection();
        // Undo only this run's own level: a caller's transaction (a test's
        // RefreshDatabase, say) is not the import's to roll back.
        $base = $connection->transactionLevel();
        $connection->beginTransaction();
        try {
            foreach ($sheet->rows as $raw) {
                $extra = array_filter(array_slice($raw['cells'], $width), static fn (string $cell): bool => $cell !== '');
                $message = $extra !== [] ? 'This row has more cells than the header has columns.' : null;
                if ($message === null) {
                    $cells = [];
                    foreach ($positions as $column => $index) {
                        $cells[$column] = $raw['cells'][$index] ?? '';
                    }
                    $row = new ImportRow($raw['line'], $cells, $order);
                    try {
                        $outcome = $connection->transaction(static fn (): ImportOutcome => $import->row($actor, $context, $row));
                        $counts[$outcome->value]++;
                    } catch (Throwable $e) {
                        if (self::endsTransaction($e)) {
                            throw $e;
                        }
                        $message = self::message($e, $import, $raw['line']);
                    }
                }
                if ($message !== null) {
                    $failed++;
                    if (count($errors) < ImportReport::LISTED) {
                        $errors[] = ['row' => $raw['line'], 'message' => $message];
                    }
                }
            }
        } catch (Throwable $e) {
            self::unwind($connection, $base);
            if (self::endsTransaction($e)) {
                return ImportReport::refused(0, 'Someone else saved a change at the same moment. Nothing was imported — try again.');
            }

            throw $e;
        }

        $saved = $keep && $failed === 0;
        if ($saved) {
            $connection->commit();
        } else {
            $connection->rollBack($base);
        }

        return new ImportReport(
            rows: count($sheet->rows),
            created: $counts[ImportOutcome::CREATED->value],
            updated: $counts[ImportOutcome::UPDATED->value],
            unchanged: $counts[ImportOutcome::UNCHANGED->value],
            failed: $failed,
            errors: $errors,
            notices: $notices,
            saved: $saved,
        );
    }

    /**
     * Header cells matched to the layout, ignoring case and spacing.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $expected
     * @return array{0: array<string, int>, 1: list<string>, 2: list<string>}
     */
    private static function columns(array $headers, array $expected): array
    {
        $index = [];
        $problems = [];
        foreach ($headers as $position => $header) {
            $key = self::normalize($header);
            if ($key === '') {
                continue;
            }
            if (isset($index[$key])) {
                $problems[] = "The column \"{$header}\" appears twice.";

                continue;
            }
            $index[$key] = $position;
        }

        $positions = [];
        $missing = [];
        foreach ($expected as $column) {
            $key = self::normalize($column);
            if (! isset($index[$key])) {
                $missing[] = $column;

                continue;
            }
            $positions[$column] = $index[$key];
            unset($index[$key]);
        }
        if ($missing !== []) {
            $problems[] = (count($missing) === 1 ? 'Missing column: ' : 'Missing columns: ').implode(', ', $missing).'. Start from the template or an export of this table.';
        }

        return [$positions, $problems, array_values(array_map(static fn (int $position): string => $headers[$position], $index))];
    }

    private static function normalize(string $header): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $header)));
    }

    /**
     * Day-first or month-first, decided once from every slashed date in the
     * file (see ImportRow), and whether the file never said which.
     *
     * @return array{0: string, 1: bool}
     */
    private static function dateOrder(CsvSheet $sheet): array
    {
        [$seen, $dayFirst, $monthFirst] = [false, false, false];
        foreach ($sheet->rows as $row) {
            foreach ($row['cells'] as $cell) {
                if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-]\d{4}$#', $cell, $m) !== 1) {
                    continue;
                }
                $seen = true;
                $dayFirst = $dayFirst || (int) $m[1] > 12;
                $monthFirst = $monthFirst || (int) $m[2] > 12;
            }
        }

        return match (true) {
            $dayFirst && $monthFirst => [ImportRow::MIXED, false],
            $dayFirst => [ImportRow::DAY_FIRST, false],
            default => [ImportRow::MONTH_FIRST, $seen && ! $monthFirst],
        };
    }

    /** The sentence the dialog shows for a refused row. Unexpected failures are logged, never shown. */
    private static function message(Throwable $e, TableImport $import, int $line): string
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return 'This row repeats a value that must be unique.';
        }
        if ($e instanceof ValidationException) {
            return (string) ($e->validator->errors()->first() ?: $e->getMessage());
        }
        if ($e instanceof HttpExceptionInterface) {
            return $e->getMessage() !== '' ? $e->getMessage()
                : ($e->getStatusCode() === 404 ? 'That record no longer exists.' : 'You cannot change this record.');
        }
        if ($e instanceof ModelNotFoundException) {
            return 'That record no longer exists.';
        }
        if ($e instanceof RuntimeException && ! $e instanceof QueryException) {
            return $e->getMessage();
        }

        // Never the message: a QueryException's carries the SQL with its bound
        // values (a salary, a bank number, an address), and the host keeps
        // error-level lines. The class, the driver's error code and the
        // throwing line are enough to find the fault.
        Log::error('[import] row failed', [
            'import' => $import::class,
            'row' => $line,
            'error' => $e::class,
            'code' => $e instanceof QueryException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        return $e instanceof QueryException ? 'The database refused this row.' : 'This row could not be imported.';
    }

    /**
     * A deadlock, lock timeout or dropped connection: the server has already
     * abandoned the transaction, so no savepoint is left to undo one row.
     */
    private static function endsTransaction(Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)
            || app(LostConnectionDetector::class)->causedByLostConnection($e);
    }

    private static function unwind(Connection $connection, int $base): void
    {
        try {
            if ($connection->transactionLevel() > $base) {
                $connection->rollBack($base);
            }
        } catch (Throwable) {
            // The server already ended the transaction; there is nothing left to undo.
        }
    }
}
