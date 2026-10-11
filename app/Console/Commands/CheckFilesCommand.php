<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Files\FileConsistency;
use Illuminate\Console\Command;

final class CheckFilesCommand extends Command
{
    protected $signature = 'ems:check-files';

    protected $description = 'Report missing files and unreferenced files older than 24 hours without changing anything';

    public function handle(FileConsistency $files): int
    {
        $report = $files->inspect();
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $report['missing'] === [] && $report['unreferenced'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
