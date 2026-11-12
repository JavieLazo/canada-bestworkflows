<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

/**
 * Form normalization and validation shared with non-Livewire hire operations.
 *
 * stepErrors() is the per-step gate of the hire wizard (add-employee-dialog
 * .tsx:110-133) and, run over every step, the first thing CreateEmployee does.
 * fieldError() is its per-answer half, which EmployeeSectionWriter applies to
 * both management edits and employee proposals. Optional fields retain the
 * reference's nullable PATCH; the writer also enforces mandatory database
 * columns so an empty name or employment status fails before persistence.
 * payload() turns a form that PASSED those checks into the row: it collapses a
 * non-numeric amount to 0.0 without complaint, exactly as the source's
 * Number(value || 0) does (NULL for a `nullable` amount — blankAmount()), so
 * a caller MUST run stepErrors first.
 *
 * DELIBERATE DIVERGENCE — the reference's per-step gate knows three rules:
 * required, positive number, email. This step also refuses, under the field
 * and on its own step, what the reference catches only at Create as a toast: a
 * date that is not a real calendar day, a birthday in the future, an amount
 * with more than two decimals or in a spelling the money box cannot produce,
 * a value over its schema cap, and an option off a fixed select. The
 * contact-number shape (PhoneRule) has no reference counterpart at all. The
 * cross-field date rules and the existence checks still run at Create, in
 * EmployeeRules, where both dates and the reference lists are known.
 *
 * The children's list (the one `children`-typed field) is not a string, so
 * it has its own pair: childrenError() is its per-answer rule, applied on its
 * step and by every save path, and children() is its normalised payload — the
 * rows to store, blank rows dropped. An Other info list (`lines`) has the
 * same pair, linesError() and lines(); a declaration (`answer`) is checked
 * against its three options and stored NULL, true or false
 * (EmployeeFields::answerValue()).
 *
 * A field with a `when` (Specify, the dual-citizenship parts, the permanent
 * address parts) is checked only while shown and saved as NULL while hidden,
 * so a value left behind by an earlier answer never lingers;
 * conditionalErrors() is its required rule on the edit paths. A `metres`
 * height is typed in metres and saved in centimetres (amount()). split()
 * divides a payload by where each value lives: the profile's columns, the
 * details row, the two address rows and the Other info lists.
 */
final class EmployeeInput
{
    /**
     * @param  array<string,mixed>  $form
     * @return array<string,mixed>
     */
    public static function payload(array $form): array
    {
        $boolean = EmployeeFields::booleanKeys();
        $numeric = EmployeeFields::numericKeys();
        $payload = [];

        foreach (EmployeeFields::allFields() as $field) {
            $key = (string) $field['key'];
            $value = $form[$key] ?? '';

            if (isset($field['choice'])) {
                // Position: the choice, not the title (EmployeePosition).
                $payload[(string) $field['choice']] = EmployeePosition::choice($form[(string) $field['choice']] ?? '');
            } elseif (isset($field['when']) && ! EmployeeFields::shown($field, $form)) {
                $payload[$key] = null;
            } elseif (EmployeeFields::isChildren($field)) {
                $payload[$key] = self::children($value);
            } elseif (EmployeeFields::isLines($field)) {
                $payload[$key] = self::lines($value);
            } elseif (EmployeeFields::isAnswer($field)) {
                $payload[$key] = EmployeeFields::answerValue(self::text($value));
            } elseif (in_array($key, $boolean, true)) {
                $payload[$key] = EmployeeFields::asBool($form[$key] ?? ($field['default'] ?? ''));
            } elseif (in_array($key, $numeric, true)) {
                $payload[$key] = self::amount($field, self::text($value));
            } else {
                $text = self::text($value);
                $payload[$key] = $text === '' ? null : $text;
            }
        }

        return $payload;
    }

    /**
     * One message per offending field on a wizard step (EmployeeFields::
     * wizard()), keyed by field. A list step (`lists`) may be left empty; a
     * row it does carry is checked through PdsLists, which reports the first
     * problem under `rows_<list>` ("School 2: Name of school is required.")
     * and each row's own problems under `rows_<list>.<row>.<field>`, the
     * path the wizard binds that input to.
     *
     * @param  array<string,mixed>  $form
     * @return array<string,string>
     */
    public static function stepErrors(array $form, int $step): array
    {
        $wizard = EmployeeFields::wizard()[$step] ?? null;

        if ($wizard === null) {
            return [];
        }

        $errors = [];

        foreach ($wizard['sections'] ?? [] as $id) {
            $section = EmployeeFields::section($id);

            if ($section !== null) {
                $errors += self::sectionErrors($section, $form);
            }
        }

        foreach ($wizard['lists'] ?? [] as $list) {
            $rows = $form['rows_'.$list] ?? [];
            $message = PdsLists::rowsError($list, $rows);

            if ($message === null) {
                continue;
            }

            $errors['rows_'.$list] = $message;

            foreach (PdsLists::rowErrors($list, $rows) as $index => $rowErrors) {
                foreach ($rowErrors as $key => $rowMessage) {
                    $errors["rows_{$list}.{$index}.{$key}"] = $rowMessage;
                }
            }
        }

        return $errors;
    }

    /**
     * One record section's messages on its wizard step: each field shown,
     * required where it is, in shape; the Addresses group rule besides.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $form
     * @return array<string, string>
     */
    private static function sectionErrors(array $section, array $form): array
    {
        $errors = [];

        foreach ($section['fields'] as $field) {
            if (isset($field['derived']) || ! EmployeeFields::shown($field, $form)) {
                continue;
            }

            $key = (string) $field['key'];
            $label = (string) $field['label'];
            // Position is answered under its choice key (EmployeePosition);
            // its message stays keyed by the field.
            $value = $form[(string) ($field['choice'] ?? $key)] ?? '';

            if (EmployeeFields::isChildren($field) || EmployeeFields::isLines($field)) {
                $message = EmployeeFields::isChildren($field) ? self::childrenError($value) : self::linesError($value);

                if ($message !== null) {
                    $errors[$key] = $message;
                }

                continue;
            }

            $text = is_bool($value) ? ($value ? 'true' : 'false') : self::text($value);

            // The position is picked from one department's list, so the
            // department is required with it. The wizard carries the pick
            // as a draft beside the form: "Not placed" names no department,
            // and neither does a blank draft with no position chosen. A
            // caller with no such draft (the command called without the
            // screen) is judged by the position alone.
            if (isset($field['choice']) && array_key_exists('department_id', $form)) {
                $department = self::text($form['department_id']);

                if ($department === EmployeePosition::NOT_PLACED) {
                    $errors['department'] = 'Department is required — a position that is not placed has none. Place it in a department on the Position page first.';
                } elseif ($department === '' && $text === '') {
                    $errors['department'] = 'Department is required.';
                }
            }

            if ($text === '' && EmployeeFields::isRequired($key, $form)) {
                $errors[$key] = isset($field['requiredWhenShown'])
                    ? (string) $field['requiredWhenShown']
                    : "{$label} is required.";

                continue;
            }

            $message = self::fieldError($field, $text);

            if ($message !== null) {
                $errors[$key] = $message;
            }
        }

        if ($section['id'] === 'addresses') {
            // The hire requires an address: at least one residential part.
            // A permanent address that is not the same may be left blank.
            if (self::addressBlank($form, 'RESIDENTIAL')) {
                $errors['residential_address'] = 'Residential address is required — fill in at least one part.';
            }
        }

        return $errors;
    }

    /**
     * The `requiredWhenShown` answers a section is missing, keyed by field —
     * the conditional half of "required" on the edit paths (the record page
     * and EmployeeSectionWriter), which otherwise keep the nullable
     * PATCH: Specify while Other is picked, both dual-citizenship parts while
     * Dual citizenship is.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $form
     * @return array<string, string>
     */
    public static function conditionalErrors(array $section, array $form): array
    {
        $errors = [];

        foreach ((array) ($section['fields'] ?? []) as $field) {
            if (! is_array($field) || ! isset($field['requiredWhenShown']) || ! EmployeeFields::shown($field, $form)) {
                continue;
            }

            $key = (string) $field['key'];

            if (self::text($form[$key] ?? '') === '') {
                $errors[$key] = (string) $field['requiredWhenShown'];
            }
        }

        return $errors;
    }

    /**
     * A payload divided by where each value lives: the `employee_profiles`
     * columns (without the children, which are rows of their own), the
     * details row's columns, and the two address rows — NULL for the
     * addresses when the payload carries no address part, else per type its
     * parts, NULL when every part is blank and, for the permanent row, while
     * it is the same as the residential one — and the Other info lists the
     * payload carries, stored kind => lines (a kind it does not carry is not
     * in `items`, so its rows are left alone).
     *
     * @param  array<string, mixed>  $payload
     * @return array{columns: array<string, mixed>, pds: array<string, mixed>, addresses: array{RESIDENTIAL: ?array<string, ?string>, PERMANENT: ?array<string, ?string>}|null, items: array<string, list<string>|null>}
     */
    public static function split(array $payload): array
    {
        $columns = [];
        $pds = [];
        $items = [];
        $parts = ['RESIDENTIAL' => [], 'PERMANENT' => []];
        $carriesAddress = false;

        foreach ($payload as $key => $value) {
            $field = EmployeeFields::field((string) $key);
            $store = $field['store'] ?? null;

            if ($key === 'children') {
                continue;
            }

            if ($store === 'items') {
                $items[(string) $field['kind']] = is_array($value) ? array_values(array_map(static fn (mixed $line): string => self::text($line), $value)) : null;

                continue;
            }

            if ($store === 'pds') {
                $pds[$key] = $value;
            } elseif ($store === 'address') {
                $carriesAddress = true;
                $parts[(string) $field['address']][(string) $field['part']] = is_string($value) && trim($value) !== '' ? $value : null;
            } else {
                $columns[$key] = $value;
            }
        }

        if (! $carriesAddress) {
            return ['columns' => $columns, 'pds' => $pds, 'addresses' => null, 'items' => $items];
        }

        $rows = [];

        foreach ($parts as $type => $typeParts) {
            $rows[$type] = array_filter($typeParts, static fn (?string $part): bool => $part !== null) === [] ? null : $typeParts;
        }

        if (($pds['permanent_address_same'] ?? null) === true) {
            $rows['PERMANENT'] = null;
        }

        return ['columns' => $columns, 'pds' => $pds, 'addresses' => ['RESIDENTIAL' => $rows['RESIDENTIAL'], 'PERMANENT' => $rows['PERMANENT']], 'items' => $items];
    }

    /**
     * The keys an audit sentence names: the seven parts of an address read
     * as one "residential_address" or "permanent_address", the spouse's parts
     * as "spouse", the parents' as "parents" and every declaration and its
     * details as "declarations" — in order, once. The audit names what
     * changed, NEVER a declaration's answer or detail.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function auditKeys(array $keys): array
    {
        $named = [];

        foreach ($keys as $key) {
            $name = match (true) {
                str_starts_with($key, EmployeeFields::ADDRESS_PREFIX['RESIDENTIAL']) => 'residential_address',
                str_starts_with($key, EmployeeFields::ADDRESS_PREFIX['PERMANENT']) => 'permanent_address',
                str_starts_with($key, 'spouse_') => 'spouse',
                str_starts_with($key, 'father_'), str_starts_with($key, 'mother_') => 'parents',
                str_starts_with($key, 'decl_') => 'declarations',
                $key === EmployeePosition::FORM_KEY => 'position',
                default => $key,
            };

            if (! in_array($name, $named, true)) {
                $named[] = $name;
            }
        }

        return $named;
    }

    /**
     * A typed number as stored: blank or non-numeric as blankAmount(); a
     * `metres` height converted to centimetres by moving the decimal point,
     * never by float multiplication (1.655 → 165.5, 1.13 → 113.0). A caller
     * MUST have run fieldError() first.
     *
     * @param  array<string, mixed>  $field
     */
    public static function amount(array $field, string $text): ?float
    {
        $text = trim($text);

        if ($text === '' || ! is_numeric($text)) {
            return self::blankAmount($field);
        }

        if (empty($field['metres'])) {
            return (float) $text;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $tenThousandths = (int) $whole * 10000 + (int) str_pad(substr($fraction, 0, 4), 4, '0');

        return (float) (intdiv($tenThousandths, 100).'.'.str_pad((string) ($tenThousandths % 100), 2, '0', STR_PAD_LEFT));
    }

    /**
     * Whether every part of one address type is blank in the form.
     *
     * @param  array<string, mixed>  $form
     */
    private static function addressBlank(array $form, string $type): bool
    {
        foreach (array_keys(EmployeeFields::ADDRESS_PARTS) as $part) {
            if (self::text($form[EmployeeFields::ADDRESS_PREFIX[$type].$part] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A height as typed in metres: digits with at most four decimals (so a
     * stored centimetre value reads back exactly), no sign or exponent, and
     * at most the centimetre cap in metres. "168" is a centimetre figure
     * typed in the wrong unit, and the message says how to type it.
     */
    private static function metresError(string $label, string $text): ?string
    {
        if (preg_match('/^\d*(?:\.\d{0,4})?$/', $text) !== 1 || ! is_numeric($text)) {
            return "{$label} must be in metres, like 1.68.";
        }

        if ((float) $text > EmployeeFields::NUMERIC_CAPS['height_cm'] / 100) {
            return "{$label} must be at most 3 metres — 168 cm is typed 1.68.";
        }

        return null;
    }

    /**
     * Why one answer is not in the shape its field asks for, or null. Blank is
     * never a shape problem here (required is stepErrors' own check), and a
     * switch has no shape. The type decides the rule; the schema caps
     * (TEXT_CAPS / NUMERIC_CAPS) apply after it, so junk is named as junk
     * before it is named as too long.
     *
     * @param  array<string, mixed>  $field
     */
    public static function fieldError(array $field, string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $key = (string) $field['key'];
        $label = (string) $field['label'];
        $type = $field['type'] ?? null;

        // Before the amount rule, which would refuse "1.625" as too many
        // decimals and pass "168" as an amount.
        if (! empty($field['metres'])) {
            return self::metresError($label, $text);
        }

        if (($field['rule'] ?? null) === 'zip' && preg_match('/^\d{4}$/', $text) !== 1) {
            return 'ZIP code must be 4 digits.';
        }

        if (($field['rule'] ?? null) === 'year' && (preg_match('/^\d{4}$/', $text) !== 1 || (int) $text < 1900 || (int) $text > 2100)) {
            return "{$label} must be a four-digit year, like 2015.";
        }

        if ($type === 'number' || $type === 'money') {
            return self::amountError($key, $label, $text);
        }

        $message = match ($type) {
            'email' => EmployeeFields::emailError($text),
            'tel' => EmployeeFields::phoneError($text),
            'date' => self::dateError($key, $label, $text),
            'select', 'answer' => self::optionError($field, $label, $text),
            default => null,
        };

        if ($message !== null) {
            return $message;
        }

        $cap = EmployeeFields::TEXT_CAPS[$key] ?? null;

        if ($cap !== null && mb_strlen($text) > $cap) {
            return "{$label} must be at most {$cap} characters.";
        }

        return null;
    }

    /**
     * Why the children's list is not in shape, or null. The list is what the
     * record page and the wizard bind — rows of `name` and `birth_date` —
     * and anything else is a tampered request. A row left entirely blank is
     * not a problem (children() drops it); a birthdate with no name is, and
     * so are an overlong name, a birthdate that is not a real calendar day
     * or lies in the future, and more rows than the sheet can hold.
     */
    public static function childrenError(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_array($value)) {
            return 'Children must be a list of names and birthdates.';
        }

        $rows = 0;

        foreach ($value as $row) {
            if (! is_array($row)) {
                return 'Children must be a list of names and birthdates.';
            }

            $name = self::text($row['name'] ?? '');
            $born = self::text($row['birth_date'] ?? '');

            if ($name === '' && $born === '') {
                continue;
            }

            $rows++;

            if ($name === '') {
                return "Each child's birthdate needs the child's name beside it.";
            }

            if (mb_strlen($name) > EmployeeFields::CHILD_NAME_CAP) {
                return "A child's name must be at most ".EmployeeFields::CHILD_NAME_CAP.' characters.';
            }

            if ($born !== '' && ! EmployeeFields::isCalendarDay($born)) {
                return "A child's birthdate must be a real calendar date.";
            }

            if ($born !== '' && $born > EmployeeFields::todayKey()) {
                return "A child's birthdate cannot be in the future.";
            }
        }

        if ($rows > EmployeeFields::MAX_CHILDREN) {
            return 'At most '.EmployeeFields::MAX_CHILDREN.' children can be listed.';
        }

        return null;
    }

    /**
     * The children's list as the rows to store, in the order typed: names
     * trimmed, a blank birthdate as NULL, rows left entirely blank dropped.
     * A caller MUST run childrenError() first — this collapses whatever is
     * left without complaint, exactly as payload() does for an amount.
     *
     * @return list<array{name: string, birth_date: ?string}>
     */
    public static function children(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = self::text($row['name'] ?? '');
            $born = self::text($row['birth_date'] ?? '');

            if ($name === '' && $born === '') {
                continue;
            }

            $rows[] = ['name' => $name, 'birth_date' => $born === '' ? null : $born];
        }

        return $rows;
    }

    /**
     * Why an Other info list is not in shape, or null: it is what the record
     * page and the wizard bind — a list of text lines — and anything else is
     * a tampered request. A blank line is not a problem (lines() drops it);
     * an overlong line is, and so are more lines than a list holds.
     */
    public static function linesError(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_array($value)) {
            return 'The list must be a list of lines.';
        }

        $count = 0;

        foreach (array_values($value) as $index => $line) {
            if (! is_string($line) && ! is_int($line) && ! is_float($line) && $line !== null) {
                return 'The list must be a list of lines.';
            }

            $text = self::text($line);

            if ($text === '') {
                continue;
            }

            $count++;

            if (mb_strlen($text) > EmployeeFields::ITEM_CAP) {
                return 'Line '.($index + 1).' must be at most '.EmployeeFields::ITEM_CAP.' characters.';
            }
        }

        if ($count > EmployeeFields::MAX_ITEMS) {
            return 'At most '.EmployeeFields::MAX_ITEMS.' lines can be listed.';
        }

        return null;
    }

    /**
     * An Other info list as the lines to store, in the order typed: trimmed,
     * blank lines dropped. A caller MUST run linesError() first.
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
    {
        $lines = [];

        foreach (is_array($value) ? $value : [] as $line) {
            $text = self::text($line);

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * What a blank amount saves as: 0, as the source's Number(value || 0)
     * does, or NULL for a field marked `nullable`, whose blank means "no
     * value" (Rate Per Day). UpdateEmployee saves the same.
     *
     * @param  array<string, mixed>  $field
     */
    public static function blankAmount(array $field): ?float
    {
        return ($field['nullable'] ?? false) === true ? null : 0.0;
    }

    /**
     * The answer as the rules read it: trimmed, and anything that is not a
     * string or a number — a tampered array, a boolean on a text key — read as
     * blank, so it meets "is required" instead of "Array to string conversion".
     */
    public static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /**
     * An amount as the money box and the number box hand it over: digits with
     * at most two decimals (".5" and "25000." are mid-typing spellings the box
     * settles itself, so they pass), never a sign or an exponent, and no
     * higher than the schema's cap (NUMERIC_CAPS). The reference accepts
     * anything Number() can read, so "1e5" and "12.345" reach the
     * DECIMAL(12,2) column, which rounds the latter without a word.
     */
    private static function amountError(string $key, string $label, string $text): ?string
    {
        if (! is_numeric($text) || (float) $text < 0) {
            return "{$label} must be a positive number.";
        }

        if (preg_match('/^\d*(?:\.\d{0,2})?$/', $text) !== 1) {
            return "{$label} must be a plain amount with at most two decimals, like 25000.50.";
        }

        $cap = EmployeeFields::NUMERIC_CAPS[$key] ?? null;

        if ($cap !== null && (float) $text > $cap) {
            return "{$label} must be at most ".number_format($cap).'.';
        }

        return null;
    }

    /**
     * A date field on its own step. The kit date-field binds only a real
     * `YYYY-MM-DD` key or '', so anything else is a tampered request. Two
     * single-field calendar rules follow: a birthday in the future (the one
     * of employee-policy.ts) and a criminal case's filing date in the future
     * (a case cannot be filed tomorrow). The cross-field rules (birthday after the date
     * joined, regularized before joined, …) span steps and stay in
     * EmployeeRules::creating(), where both dates are known.
     */
    private static function dateError(string $key, string $label, string $text): ?string
    {
        if (! EmployeeFields::isCalendarDay($text)) {
            return "{$label} must be a real calendar date.";
        }

        if (in_array($key, ['birth_date', 'decl_35b_date_filed'], true) && $text > EmployeeFields::todayKey()) {
            return "{$label} cannot be in the future.";
        }

        return null;
    }

    /**
     * A select with a fixed option list (Sex at birth, Civil Status,
     * Citizenship, Dual citizenship, a declaration's answer) only ever
     * offers those values, so anything else is a tampered request. Lists that
     * come from Records Management (`source`) and the worksite (`site`) are
     * checked against the database at Create, in EmployeeRules, not here.
     *
     * @param  array<string, mixed>  $field
     */
    private static function optionError(array $field, string $label, string $text): ?string
    {
        $options = $field['options'] ?? null;

        if (! is_array($options) || $options === []) {
            return null;
        }

        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $text) {
                return null;
            }
        }

        return "{$label} must be one of the listed options.";
    }
}
