<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\CodeSequence;
use App\Support\EmailRule;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use App\Support\TextSort;
use Carbon\CarbonImmutable;

/** Compatibility adapter; implementation belongs to \App\Modules\Workforce\Application\EmployeeFields. */
final class EmployeeFields
{
    public const string CODE_PREFIX = \App\Modules\Workforce\Application\EmployeeFields::CODE_PREFIX;

    /**
     * attachments.ts:20 — API mode: 2 MB per stored file. This is only the
     * FALLBACK when `copems.uploads.max_file_bytes` is unset; read the cap
     * through {@see self::maxFileBytes()}, never this constant.
     */
    public const int MAX_FILE_BYTES = \App\Modules\Workforce\Application\EmployeeFields::MAX_FILE_BYTES;

    /** portrait.ts:15 — acceptance cap on a picked photo, not what is stored. */
    public const int MAX_PHOTO_BYTES = \App\Modules\Workforce\Application\EmployeeFields::MAX_PHOTO_BYTES;

    /** portrait.ts:18-19 — longest edge of the stored portrait, JPEG quality. */
    public const int PORTRAIT_MAX_EDGE = \App\Modules\Workforce\Application\EmployeeFields::PORTRAIT_MAX_EDGE;

    public const int PORTRAIT_JPEG_QUALITY = \App\Modules\Workforce\Application\EmployeeFields::PORTRAIT_JPEG_QUALITY;

    /**
     * The per-file cap every upload surface applies — `copems.uploads
     * .max_file_bytes` (COPEMS_MAX_UPLOAD_BYTES), falling back to the
     * reference's 2 MB. Documents, My Tasks and the BRIEF path in Projects
     * all read the same key, so one env var moves all three together.
     */
    public static function maxFileBytes(): int
    {
        return \App\Modules\Workforce\Application\EmployeeFields::maxFileBytes();
    }

    /**
     * storage.ts:15 — the server's 1 GB sanity ceiling across every
     * attachment, overridable per install through `copems.uploads
     * .storage_ceiling_bytes` (COPEMS_STORAGE_CEILING_BYTES). env() is
     * consulted only in config/copems.php, so `config:cache` is safe.
     */
    public static function storageCeilingBytes(): int
    {
        return \App\Modules\Workforce\Application\EmployeeFields::storageCeilingBytes();
    }

    /** @var list<array{value: string, label: string}> */
    public const array SEX = \App\Modules\Workforce\Application\EmployeeFields::SEX;

    /** @var list<array{value: string, label: string}> */
    public const array CIVIL = \App\Modules\Workforce\Application\EmployeeFields::CIVIL;

    /** @var list<array{value: string, label: string}> */
    public const array BLOOD_TYPES = \App\Modules\Workforce\Application\EmployeeFields::BLOOD_TYPES;

    public const int MAX_CHILDREN = \App\Modules\Workforce\Application\EmployeeFields::MAX_CHILDREN;

    public const int CHILD_NAME_CAP = \App\Modules\Workforce\Application\EmployeeFields::CHILD_NAME_CAP;

    /** The Educational Background levels, stored value => the dialog's label. */
    public const array EDUCATION_LEVELS = \App\Modules\Workforce\Application\EmployeeFields::EDUCATION_LEVELS;

    /** The Educational Background levels, stored value => the table cell's label. */
    public const array EDUCATION_LEVEL_SHORT = \App\Modules\Workforce\Application\EmployeeFields::EDUCATION_LEVEL_SHORT;

    /** A declaration's answer: Not answered, Yes or No. */
    public const array ANSWERS = \App\Modules\Workforce\Application\EmployeeFields::ANSWERS;

    /** A previous job's "Gov't service": unanswered, Y or N. */
    public const array GOV_SERVICE = \App\Modules\Workforce\Application\EmployeeFields::GOV_SERVICE;

    /** The two stems printed above their declarations, keyed by the first question under each. */
    public const array DECLARATION_STEMS = \App\Modules\Workforce\Application\EmployeeFields::DECLARATION_STEMS;

    /** The most lines an Other info list holds. */
    public const int MAX_ITEMS = \App\Modules\Workforce\Application\EmployeeFields::MAX_ITEMS;

    /** Each Other info list's field key => its stored kind. */
    public const array ITEM_KINDS = \App\Modules\Workforce\Application\EmployeeFields::ITEM_KINDS;

    /**
     * employee-documents.tsx BUILT_IN_CATEGORIES — kept only so documents
     * filed as `GOVERNMENT_ID` still read as "Government ID".
     *
     * @var array<string, string>
     */
    public const array BUILT_IN_CATEGORIES = \App\Modules\Workforce\Application\EmployeeFields::BUILT_IN_CATEGORIES;

    /**
     * The dialog's REQUIRED set (add-employee-dialog.tsx:31-49) — everything
     * HR needs on day one. NEITHER identifier is here; the owner's copy says
     * why, and is the one to edit.
     *
     * @var list<string>
     */
    public const array REQUIRED = \App\Modules\Workforce\Application\EmployeeFields::REQUIRED;

    /**
     * REQUIRED, except Rate Per Month once a Rate Per Day is entered. The
     * owner's copy says why.
     *
     * @param  array<string, mixed>  $form
     */
    public static function isRequired(string $key, array $form): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isRequired($key, $form);
    }

    /**
     * SECTIONS, transcribed field for field from employee-fields.tsx:70-176.
     * The owner holds the field list, the key legend and the two deliberate
     * divergences about the identifiers — read it there rather than keeping a
     * second description here, which would drift apart from it.
     *
     * @return list<array{id: string, title: string, column: string, columns?: int, hr?: bool, description?: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::sections();
    }

    /**
     * Every non-derived field, flat (ALL_FIELDS in the source).
     *
     * @return list<array<string, mixed>>
     */
    public static function allFields(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::allFields();
    }

    /** @return array<string, mixed>|null */
    public static function section(string $id): ?array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::section($id);
    }

    /** @return list<string> keys typed number or money (NUMERIC in the source) */
    public static function numericKeys(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::numericKeys();
    }

    /** @return list<string> keys typed switch (BOOLEAN in the source) */
    public static function booleanKeys(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::booleanKeys();
    }

    /** Collapsed into a single "Full Name" row outside edit mode. */
    public const array NAME_PARTS = \App\Modules\Workforce\Application\EmployeeFields::NAME_PARTS;

    /**
     * blankForm() (add-employee-dialog.tsx:52-69): '' everywhere, booleans
     * true, then the sensible starting points.
     *
     * @return array<string, mixed>
     */
    public static function blankForm(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::blankForm();
    }

    /**
     * toForm() (employee-fields.tsx:186-193): the record as editable strings
     * and booleans. Dates come out as the `YYYY-MM-DD` key the DateField binds.
     *
     * @return array<string, mixed>
     */
    public static function toForm(EmployeeProfile|EmployeeProfileView $employee): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::toForm($employee);
    }

    /** A form/select boolean however Livewire round-tripped it. */
    public static function asBool(mixed $value): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::asBool($value);
    }

    /** @var list<array{value: string, label: string}> */
    public const array CITIZENSHIP = \App\Modules\Workforce\Application\EmployeeFields::CITIZENSHIP;

    /** @var list<array{value: string, label: string}> */
    public const array DUAL_CITIZENSHIP_BY = \App\Modules\Workforce\Application\EmployeeFields::DUAL_CITIZENSHIP_BY;

    /** @var array<string, string> */
    public const array ADDRESS_PARTS = \App\Modules\Workforce\Application\EmployeeFields::ADDRESS_PARTS;

    /** @var array<string, string> */
    public const array ADDRESS_PREFIX = \App\Modules\Workforce\Application\EmployeeFields::ADDRESS_PREFIX;

    /** @return array<string, mixed>|null */
    public static function field(string $key): ?array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::field($key);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $form
     */
    public static function shown(array $field, array $form): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::shown($field, $form);
    }

    /** @return array<string, string> */
    public static function addressParts(EmployeeProfile|EmployeeProfileView $employee, string $type): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::addressParts($employee, $type);
    }

    /** @param  array<string, string>  $parts */
    public static function addressLine(array $parts): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::addressLine($parts);
    }

    public static function residentialText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::residentialText($employee);
    }

    public static function permanentText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::permanentText($employee);
    }

    public static function legacyAddress(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::legacyAddress($employee);
    }

    public static function legacyCitizenship(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::legacyCitizenship($employee);
    }

    public static function citizenshipText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::citizenshipText($employee);
    }

    public static function civilStatusText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::civilStatusText($employee);
    }

    /** @param  array<string, mixed>  $field */
    public static function isAnswer(array $field): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isAnswer($field);
    }

    /** @param  array<string, mixed>  $field */
    public static function isLines(array $field): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isLines($field);
    }

    /** A stored answer as the form's select holds it: '', 'yes' or 'no'. */
    public static function answerForm(mixed $stored): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::answerForm($stored);
    }

    /**
     * A declaration as read: its answer and "Not answered", "No" or "Yes" with its detail lines.
     *
     * @return array{answer: ?bool, text: string}
     */
    public static function declarationText(EmployeeProfile|EmployeeProfileView $employee, string $key): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::declarationText($employee, $key);
    }

    /** A calendar day as read ("04 May 2019"); '' for anything else. */
    public static function dateText(mixed $value): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::dateText($value);
    }

    /** Whether any of the spouse's parts is typed. */
    public static function spouseTyped(EmployeeProfile|EmployeeProfileView $employee): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::spouseTyped($employee);
    }

    /** The legacy spouse lines as one, while no spouse part is typed; else ''. */
    public static function legacySpouse(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::legacySpouse($employee);
    }

    /** The father's name as one line, surname first. */
    public static function fatherText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::fatherText($employee);
    }

    /** The mother's maiden name as one line, surname first. */
    public static function motherText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::motherText($employee);
    }

    /**
     * One Other info list's lines, in order.
     *
     * @return list<string>
     */
    public static function itemLines(EmployeeProfile|EmployeeProfileView $employee, string $key): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::itemLines($employee, $key);
    }

    /** The typed residential parts as one line, with no legacy fallback. */
    public static function structuredResidentialText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::structuredResidentialText($employee);
    }

    /**
     * The hire wizard's steps.
     *
     * @return list<array{id: string, title: string, sections?: list<string>, lists?: list<string>}>
     */
    public static function wizard(): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::wizard();
    }

    public static function metresText(float $cm): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::metresText($cm);
    }

    /** @param  array<string, mixed>  $form */
    public static function keptLegacyAddressError(EmployeeProfile|EmployeeProfileView $employee, array $form): ?string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::keptLegacyAddressError($employee, $form);
    }

    /**
     * readValue() (employee-fields.tsx:242-259) — one field as display text.
     *
     * @param  array<string, mixed>  $field
     */
    public static function readValue(EmployeeProfile|EmployeeProfileView $employee, array $field): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::readValue($employee, $field);
    }

    /**
     * Which read-mode values render in the tabular `num` face (Field, employee-fields.tsx:333-341).
     *
     * @param  array<string, mixed>  $field
     */
    public static function isNumFace(array $field): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isNumFace($field);
    }

    /**
     * Whether `$field` is the children's list — the one field that is not a string.
     *
     * @param  array<string, mixed>  $field
     */
    public static function isChildren(array $field): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isChildren($field);
    }

    /**
     * The employee's children as form rows, in sheet order.
     *
     * @return list<array{name: string, birth_date: string}>
     */
    public static function childRows(EmployeeProfile|EmployeeProfileView $employee): array
    {
        return \App\Modules\Workforce\Application\EmployeeFields::childRows($employee);
    }

    /** A measure as a person writes it: no trailing zeros, at most two decimals (178, 62.5). */
    public static function measure(float $value): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::measure($value);
    }

    public static function fullName(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::fullName($employee);
    }

    /**
     * employeeName() (hris.ts:425-427): first + last, plus the suffix the
     * reference leaves out. See {@see EmployeeName} for why this does not
     * compose its own.
     */
    public static function employeeName(EmployeeProfile|EmployeeProfileView|null $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::employeeName($employee);
    }

    public static function initials(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::initials($employee);
    }

    /** ageFrom() (employee-fields.tsx:224-236), on the Manila calendar. */
    public static function ageFrom(?CarbonImmutable $birth): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::ageFrom($birth);
    }

    /** yearsAndMonths() (employee-fields.tsx:205-222). */
    public static function yearsAndMonths(?CarbonImmutable $from): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::yearsAndMonths($from);
    }

    /** @param  array<string, mixed>  $field */
    public static function derivedValue(EmployeeProfile|EmployeeProfileView $employee, array $field): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::derivedValue($employee, $field);
    }

    /** "Job Order", "JOB_ORDER" and "job order" are one status (hris.ts:380-386). */
    public static function statusKey(?string $value): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::statusKey($value);
    }

    /** employmentStatusLabel (hris.ts:389-390), shared through HrisFormat; the wrapper keeps every call site. */
    public static function employmentStatusLabel(string $value): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::employmentStatusLabel($value);
    }

    public static function isRegular(?string $value): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isRegular($value);
    }

    /** categoryLabel() (employee-documents.tsx:66): token → label, names echo back. */
    public static function categoryLabel(string $value): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::categoryLabel($value);
    }

    /** Today's Manila calendar day, `YYYY-MM-DD` (hris.ts todayKey) — ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::todayKey();
    }

    /** peso() (hris.ts:180-186): ₱ + en-PH grouping, always two decimals. */
    public static function peso(float $n): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::peso($n);
    }

    /** humanSize() (attachments.ts:25-28), shared through HrisFormat — one spelling on every upload surface. */
    public static function humanSize(int $bytes): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::humanSize($bytes);
    }

    /** emailError() (hris.ts:230-238) — blank is fine; required is the form's call. One rule, EmailRule, shared with Users. */
    public static function emailError(string $value): ?string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::emailError($value);
    }

    /** The contact-number rule (PhoneRule) through the owner; no TypeScript counterpart. */
    public static function phoneError(string $value): ?string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::phoneError($value);
    }

    /**
     * The highest employee id + 1, through the one mint in CodeSequence — a
     * bare number, where hris.ts:407-414 mints the COP-00000 style.
     *
     * @param  array<array-key, string|null>  $codes
     */
    public static function nextEmployeeCode(array $codes): string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::nextEmployeeCode($codes);
    }

    /**
     * compareText() (useSort.ts:33-40): case-insensitive byte order, blanks
     * last, shared through App\Support\TextSort. Never ICU `Collator('en')`:
     * it would make the Employee Records order depend on whether ext-intl is
     * loaded on a host the company does not control; TextSort's docblock says
     * why byte order wins. The list screen sorts in PHP because statusKey/label
     * mapping cannot be expressed in SQL, exactly as the source sorts in the
     * client.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        return \App\Modules\Workforce\Application\EmployeeFields::compareText($a, $b);
    }

    /** A real `YYYY-MM-DD` calendar day (lib/payroll-period.ts payrollDate). */
    public static function isCalendarDay(string $value): bool
    {
        return \App\Modules\Workforce\Application\EmployeeFields::isCalendarDay($value);
    }

    /**
     * employeeDateError() (lib/employee-policy.ts) — the cross-field calendar
     * rules shared by create and update, message for message.
     *
     * @param  array{birth_date?: ?string, date_hired: string, date_regular?: ?string, date_resigned?: ?string}  $value
     */
    public static function employeeDateError(array $value, string $today): ?string
    {
        return \App\Modules\Workforce\Application\EmployeeFields::employeeDateError($value, $today);
    }

    /**
     * The per-field caps of `EmployeeInput` (src/lib/api/schemas.ts:11-82) —
     * transcribed so an overlong value fails on save the way the API's 400 does.
     *
     * @var array<string, int>
     */
    public const array TEXT_CAPS = \App\Modules\Workforce\Application\EmployeeFields::TEXT_CAPS;

    /** @var array<string, float> Zod numeric maxima (schemas.ts:50-79). */
    public const array NUMERIC_CAPS = \App\Modules\Workforce\Application\EmployeeFields::NUMERIC_CAPS;
}
