<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use InvalidArgumentException;

/**
 * The Pay Basis & Rates in force on each day of a payslip's period — ONE
 * immutable value the engine is handed when a version changes inside the
 * period (DELIBERATE DIVERGENCE 17).
 *
 * The reference prices a whole period under one set of figures. A version
 * dated inside a period used to be refused; now each day is priced by the
 * version in force on it, and a period with one version performs the
 * reference's exact operations: `single()` is the shape every pure caller
 * and the golden vectors take, and its one segment answers every date.
 *
 * Segments are ascending by `from`; the first covers every earlier date
 * too (it is the version in force on the period's first day). Pure: no
 * clock, database or framework.
 */
final class PayRulesTimeline
{
    /** The `from` of the first segment when it stands for "since always". */
    public const string ALWAYS = '0000-01-01';

    /**
     * @param  list<array{from: string, rules: PayRules}>  $segments
     */
    private function __construct(public readonly array $segments) {}

    /** One version for every day: the reference's shape. */
    public static function single(PayRules $rules): self
    {
        return new self([['from' => self::ALWAYS, 'rules' => $rules]]);
    }

    /**
     * Versions from their dates, ascending. The first segment's date is the
     * day it is in force from; a date before it reads the first segment.
     *
     * @param  list<array<string, mixed>>  $segments  each `from` ('Y-m-d') and `rules` (PayRules)
     */
    public static function of(array $segments): self
    {
        if ($segments === []) {
            throw new InvalidArgumentException('A pay rules timeline needs at least one version.');
        }
        $previous = null;
        $list = [];
        foreach ($segments as $segment) {
            $from = $segment['from'] ?? null;
            $rules = $segment['rules'] ?? null;
            if (! is_string($from) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || ! $rules instanceof PayRules) {
                throw new InvalidArgumentException('A pay rules timeline segment needs a date and the rules.');
            }
            if ($previous !== null && $from <= $previous) {
                throw new InvalidArgumentException('Pay rules timeline segments must ascend by date.');
            }
            $list[] = ['from' => $from, 'rules' => $rules];
            $previous = $from;
        }

        return new self($list);
    }

    /** Whether one version prices every day. */
    public function isSingle(): bool
    {
        return count($this->segments) === 1;
    }

    /** The rules in force on a date: the latest segment on or before it, else the first. */
    public function on(string $date): PayRules
    {
        $rules = $this->segments[0]['rules'];
        foreach ($this->segments as $segment) {
            if ($segment['from'] > $date) {
                break;
            }
            $rules = $segment['rules'];
        }

        return $rules;
    }

    /**
     * The day the version in force on a date started: the first day of its
     * segment (`ALWAYS` for the first), so two dates share a pricing bucket
     * exactly when the same version prices them.
     */
    public function segmentStart(string $date): string
    {
        $from = $this->segments[0]['from'];
        foreach ($this->segments as $segment) {
            if ($segment['from'] > $date) {
                break;
            }
            $from = $segment['from'];
        }

        return $from;
    }
}
