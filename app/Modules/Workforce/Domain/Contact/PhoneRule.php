<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Contact;

/**
 * Why a contact number is unacceptable, or null when it is fine.
 *
 * DELIBERATE DIVERGENCE — the reference stores whatever is typed into `phone`
 * and `emergency_phone`, capped at 40 characters (schemas.ts:32,45), so "abc"
 * is a valid Contact Number there. Every surface that writes either column
 * asks this rule first: the hire wizard and CreateEmployee (through
 * EmployeeInput), the record page's section save (UpdateEmployee) and My
 * Profile (UpdateOwnProfile).
 *
 * The rule is a SHAPE, not a numbering plan: an optional leading "+", then 7
 * to 15 digits (the E.164 ceiling) with only spaces, dots, dashes and
 * parentheses between them. That admits every spelling the company writes —
 * 0917 123 4567, 09171234567, +63 917 123 4567, (02) 8123-4567 — and refuses
 * letters, a lone digit and a number with an extension baked in ("… loc 12"),
 * which belongs in a note, not in the field. Blank is fine here; whether the
 * field is required is the form's own call, exactly as EmailRule treats an
 * address. Only ASCII digits count, so other-script digits are refused
 * rather than stored as a "number"; the invisible look-alikes a paste from a
 * phone's contacts carries (FOLD) are read as the plain character they stand
 * for, so a number that LOOKS like the example is not refused for them.
 */
final class PhoneRule
{
    public const string MESSAGE = 'A contact number is 7 to 15 digits, optionally with a leading + and spaces, dots, dashes or parentheses, like 0917 123 4567.';

    /** The characters a number may be spelled with; the digit count is checked separately. */
    private const string SHAPE = '/^\+?[0-9 .()\-]+$/';

    /**
     * Pasted look-alikes, folded before the shape is checked: no-break and
     * typographic spaces read as a space, Unicode hyphens and the minus sign
     * as a dash, and the zero-width and bidi marks iOS wraps a number in are
     * dropped. A value made ONLY of such marks folds to blank and is refused,
     * which is also what keeps "\u{00A0}" from counting as a required answer.
     *
     * @var array<string, string> pattern => replacement
     */
    private const array FOLD = [
        '/[\x{00A0}\x{2007}\x{2009}\x{202F}\x{3000}]/u' => ' ',
        '/[\x{2010}-\x{2015}\x{2212}\x{FE63}\x{FF0D}]/u' => '-',
        '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u' => '',
    ];

    private function __construct() {}

    public static function error(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $folded = preg_replace(array_keys(self::FOLD), array_values(self::FOLD), $value);
        $v = $folded === null ? '' : trim($folded);

        if ($v === '' || preg_match(self::SHAPE, $v) !== 1) {
            return self::MESSAGE;
        }

        $digits = strlen((string) preg_replace('/[^0-9]/', '', $v));

        return $digits >= 7 && $digits <= 15 ? null : self::MESSAGE;
    }
}
