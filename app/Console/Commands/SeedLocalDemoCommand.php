<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ManilaClock;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class SeedLocalDemoCommand extends Command
{
    protected $signature = 'ems:demo {--days=90 : Calendar days of sample history, from 1 to 366} {--through= : Last Manila date, default today}';

    protected $description = 'Add repeatable local attendance, leave and discussion samples without rewriting existing rows';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false) {
            $this->error('Use --days with a whole number from 1 to 366.');

            return self::FAILURE;
        }
        $through = (string) ($this->option('through') ?? ManilaClock::todayKey());
        try {
            $added = app(LocalDemoSeeder::class)->run($through, $days);
        } catch (QueryException $error) {
            report($error);
            $this->error('The demo data could not be added. Its transaction was rolled back; existing rows were retained.');

            return self::FAILURE;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Local demo history through '.$through.'. Existing records and edits were kept.');
        $this->table(['Table', 'Added'], array_map(static fn ($table, $count): array => [$table, $count], array_keys($added), array_values($added)));
        $this->line('No account, permission, leave balance, posted payroll or outbound notification was changed.');

        return self::SUCCESS;
    }
}
