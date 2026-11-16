<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Platform\Transfer\CsvReader;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BudgetPaste
{
    /** @return list<array{line: int, label: string, amount: int}> */
    public static function rows(string $text): array
    {
        if (strlen($text) > 50000 || trim($text) === '' || str_contains($text, "\0")) {
            throw ValidationException::withMessages(['pasteText' => 'Paste up to 100 rows with a cost item and amount.']);
        }
        try {
            $sheet = CsvReader::parse("Cost item\tAmount\n".$text, 100);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['pasteText' => $error->getMessage()]);
        }
        $rows = [];
        $total = 0;
        foreach ($sheet->rows as $row) {
            $line = $row['line'] - 1;
            $cells = $row['cells'];
            while (count($cells) > 2 && end($cells) === '') {
                array_pop($cells);
            }
            if (count($cells) !== 2 || $cells[0] === '' || mb_strlen($cells[0]) > 200) {
                throw ValidationException::withMessages(['pasteText' => 'Row '.$line.': use two columns, a cost item (up to 200 characters) and amount.']);
            }
            $value = $cells[1];
            if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $value)) {
                $value = str_replace(',', '', $value);
            }
            try {
                $amount = FinanceAmount::minor($value, 'pasteText');
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(['pasteText' => 'Row '.$line.': '.$error->validator->errors()->first('pasteText')]);
            }
            if ($total > FinanceAmount::LIMIT - $amount) {
                throw ValidationException::withMessages(['pasteText' => 'The pasted total is too large.']);
            }
            $rows[] = ['line' => $line, 'label' => $cells[0], 'amount' => $amount];
            $total += $amount;
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['pasteText' => 'Paste at least one cost item and amount.']);
        }

        return $rows;
    }
}
