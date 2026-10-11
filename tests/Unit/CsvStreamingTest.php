<?php

declare(strict_types=1);

use App\Support\Csv;

/** Streaming keeps Excel compatibility and does not read ahead into later batches. */
it('preserves csv quoting formula protection and lazy row consumption', function () {
    $read = 0;
    $rows = static function () use (&$read): Generator {
        $read++;
        yield ['=1+1', "A,\"B\"\r\nC", -12.5, null];
        $read++;
        yield ["\tcell", '@name', '+1', '-text'];
    };
    $chunks = Csv::chunks(['First', 'Second', 'Third', 'Fourth'], $rows());
    expect($read)->toBe(0)->and($chunks->current())->toBe(Csv::BOM.'"First","Second","Third","Fourth"')->and($read)->toBe(0);
    $chunks->next();
    expect($read)->toBe(1)->and($chunks->current())->toBe("\r\n\"'=1+1\",\"A,\"\"B\"\"\r\nC\",\"-12.5\",\"\"");
    $chunks->next();
    expect($read)->toBe(2)->and($chunks->current())->toBe("\r\n\"'\tcell\",\"'@name\",\"'+1\",\"'-text\"");
    $chunks->next();
    expect($chunks->valid())->toBeFalse();
});
