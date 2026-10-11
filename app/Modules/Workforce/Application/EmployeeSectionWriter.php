<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use RuntimeException;

/** Shared section validation and persistence; callers authorize and lock the employee first. */
final class EmployeeSectionWriter
{
    /** @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function payload(EmployeeProfile $employee, string $sectionId, array $form): array
    {
        $section = EmployeeFields::section($sectionId);
        if ($section === null) {
            throw new RuntimeException('Choose an employee record section.');
        }
        if ($sectionId === 'employment' && EmployeeInput::text($form['work_site_id'] ?? '') === '') {
            throw new RuntimeException('Assigned Worksite is required.');
        }
        $boolean = EmployeeFields::booleanKeys();
        $numeric = EmployeeFields::numericKeys();
        $payload = [];
        // The children's rows when the section carries the list; null when
        // it does not, so the rows are left alone.
        $children = null;
        foreach ($section['fields'] as $field) {
            // `auto` as well as `derived`. NO SECTION CARRIES `auto` — the
            // minted row id is not a Personal Details field, so it cannot
            // reach this loop at all — and the guard stays anyway: a
            // system-assigned value renders as a disabled input, but
            // Show::$form is an unlocked wire:model draft, so a crafted update
            // that named such a key would otherwise rewrite it through fill().
            // A row id is the one identifier the rest of the system assumes it
            // assigned itself.
            if (isset($field['derived']) || ! empty($field['auto'])) {
                continue;
            }
            $key = (string) $field['key'];
            $value = $form[$key] ?? '';
            if (isset($field['choice'])) {
                // Position: the choice (an id, blank, or KEEP), never the
                // title the form also carries. A form without the key
                // keeps what is stored rather than clearing it.
                $choiceKey = (string) $field['choice'];
                $payload[$choiceKey] = array_key_exists($choiceKey, $form) ? EmployeePosition::choice($form[$choiceKey]) : EmployeePosition::KEEP;

                continue;
            }
            // A field waiting on another answer (Specify, the dual parts,
            // the permanent parts) is saved blank while it is hidden, so
            // a value left from an earlier answer never lingers.
            if (isset($field['when']) && ! EmployeeFields::shown($field, $form)) {
                $payload[$key] = null;

                continue;
            }
            if (EmployeeFields::isLines($field)) {
                // An Other info list: checked and normalised by its own
                // pair, written as rows below (split()'s `items`).
                $error = EmployeeInput::linesError($value);
                if ($error !== null) {
                    throw new RuntimeException($error);
                }
                $payload[$key] = EmployeeInput::lines($value);

                continue;
            }
            if (EmployeeFields::isChildren($field)) {
                // Not a column: checked and normalised by its own pair, kept
                // in the normalized payload and written as rows below.
                $error = EmployeeInput::childrenError($value);
                if ($error !== null) {
                    throw new RuntimeException($error);
                }
                $children = EmployeeInput::children($value);
                $payload[$key] = $children;

                continue;
            }
            if (in_array($key, $boolean, true)) {
                $payload[$key] = EmployeeFields::asBool($form[$key] ?? ($field['default'] ?? ''));

                continue;
            }
            // The hire wizard's per-answer rules (EmployeeInput::fieldError),
            // so the record page cannot save what the hire refuses: an email
            // or contact number that is not one, a malformed date, an amount
            // in the wrong spelling or over its cap, an overlong text. The
            // page shows the same messages under the fields before it calls
            // here; this is the copy that cannot be skipped.
            $text = EmployeeInput::text($value);
            $error = EmployeeInput::fieldError($field, $text);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            if (EmployeeFields::isAnswer($field)) {
                // Not answered stays NULL — never read as No.
                $payload[$key] = EmployeeFields::answerValue($text);
            } elseif (in_array($key, $numeric, true)) {
                $payload[$key] = EmployeeInput::amount($field, $text);
            } else {
                $payload[$key] = $text === '' ? null : $text;
            }
        }
        $missing = EmployeeInput::conditionalErrors($section, $form);
        if ($missing !== []) {
            throw new RuntimeException((string) reset($missing));
        }
        if ($sectionId === 'addresses') {
            $kept = EmployeeFields::keptLegacyAddressError($employee, $form);
            if ($kept !== null) {
                throw new RuntimeException($kept);
            }
        }
        // ── The API-side guards, message for message ───────────────────────
        foreach (['first_name' => 'First Name', 'last_name' => 'Last Name', 'employment_status' => 'Employment Status'] as $key => $label) {
            if (array_key_exists($key, $payload) && $payload[$key] === null) {
                throw new RuntimeException($label.' is required.');
            }
        }
        $failure = EmployeeRules::updating($employee, $payload);
        if ($failure !== null) {
            throw new RuntimeException($failure);
        }
        // date_hired is NOT NULL on the table (and non-optional in the API
        // schema); a cleared DateField must fail like the API's 400 does.
        if (array_key_exists('date_hired', $payload) && $payload['date_hired'] === null) {
            throw new RuntimeException('Date joined must be a real calendar date.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function write(EmployeeProfile $employee, array $payload): void
    {
        $split = EmployeeInput::split($payload);
        $columns = $split['columns'];
        unset($columns[EmployeePosition::FORM_KEY]);
        $employee->fill($columns);
        if (array_key_exists(EmployeePosition::FORM_KEY, $payload)) {
            $choice = $payload[EmployeePosition::FORM_KEY];
            EmployeePosition::apply($employee, is_string($choice) ? $choice : null);
        }
        $employee->save();
        $rows = app(EmployeeDetailRows::class);
        if (array_key_exists('children', $payload)) {
            $rows->replaceChildren($employee, $payload['children']);
        }
        $rows->save($employee, $split['pds']);
        if ($split['addresses'] !== null) {
            $rows->replaceAddresses($employee, $split['addresses']);
        }
        if ($split['items'] !== []) {
            $rows->replaceItems($employee, $split['items']);
        }
    }
}
