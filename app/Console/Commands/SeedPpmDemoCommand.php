<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\PpmDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class SeedPpmDemoCommand extends Command
{
    protected $signature = 'ppm:demo {--owner= : Existing Project Management administrator account ID}';

    protected $description = 'Add the repeatable local PPM review pack without overwriting existing work';

    public function handle(): int
    {
        $input = $this->option('owner');
        $ownerId = $input === null ? null : filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($ownerId === false) {
            $this->error('Use --owner with an existing account ID.');

            return self::FAILURE;
        }
        try {
            $seeder = app(PpmDemoSeeder::class);
            $seeder->setCommand($this);
            $seeder->run($ownerId);
        } catch (QueryException $error) {
            report($error);
            $this->error('The demo pack could not be added. Its database transaction was rolled back; existing work was retained.');

            return self::FAILURE;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
