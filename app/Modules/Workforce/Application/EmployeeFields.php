<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeAddress;
use App\Models\EmployeeChild;
use App\Models\EmployeeEducation;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Modules\Workforce\Domain\Contact\PhoneRule;
use App\Platform\Files\UploadLimits;
use App\Support\CodeSequence;
use App\Support\DateText;
use App\Support\EmailRule;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * One definition of what an employee record holds — the port of
 * `src/app/(protected)/employees/employee-fields.tsx` (SECTIONS, REQUIRED,
 * NUMERIC, BOOLEAN, readValue, toForm) plus the slice of `src/lib/hris.ts`
 * this module reads (statusKey, employmentStatusLabel, isRegular, peso,
 * emailError, nextEmployeeCode, todayKey, compareText, humanSize) and the
 * cross-field date policy of `src/lib/employee-policy.ts`.
 *
 * Shared by the record page and the add-employee stepper so the two can never
 * drift apart, exactly as the source arranged it.
 */
final class EmployeeFields
{
    public const string CODE_PREFIX = 'COP-';

    /**
     * attachments.ts:20 — API mode: 2 MB per stored file. This is only the
     * FALLBACK when `copems.uploads.max_file_bytes` is unset; read the cap
     * through {@see self::maxFileBytes()}, never this constant.
     */
    public const int MAX_FILE_BYTES = 2_000_000;

    /** portrait.ts:15 — acceptance cap on a picked photo, not what is stored. */
    public const int MAX_PHOTO_BYTES = 4_000_000;

    /** portrait.ts:18-19 — longest edge of the stored portrait, JPEG quality. */
    public const int PORTRAIT_MAX_EDGE = 512;

    public const int PORTRAIT_JPEG_QUALITY = 85;

    /**
     * The per-file cap every upload surface applies — `copems.uploads
     * .max_file_bytes` (COPEMS_MAX_UPLOAD_BYTES), falling back to the
     * reference's 2 MB. Documents, My Tasks and the BRIEF path in Projects
     * all read the same key, so one env var moves all three together.
     */
    public static function maxFileBytes(): int
    {
        return UploadLimits::maxFileBytes();
    }

    /**
     * storage.ts:15 — the server's 1 GB sanity ceiling across every
     * attachment, overridable per install through `copems.uploads
     * .storage_ceiling_bytes` (COPEMS_STORAGE_CEILING_BYTES). env() is
     * consulted only in config/copems.php, so `config:cache` is safe.
     */
    public static function storageCeilingBytes(): int
    {
        return UploadLimits::storageCeilingBytes();
    }

    /** @var list<array{value: string, label: string}> */
    public const array SEX = [
        ['value' => 'Male', 'label' => 'Male'],
        ['value' => 'Female', 'label' => 'Female'],
    ];

    /** @var list<array{value: string, label: string}> */
    public const array CIVIL = [
        ['value' => 'Single', 'label' => 'Single'],
        ['value' => 'Married', 'label' => 'Married'],
        ['value' => 'Widowed', 'label' => 'Widowed'],
        ['value' => 'Separated', 'label' => 'Separated'],
        // Stored as "Other"; the specification is `civil_status_other`, in
        // the employee's details row.
        ['value' => 'Other', 'label' => 'Other/s'],
    ];

    /**
     * The structured citizenship (`employee_pds_details.citizenship_kind`,
     * CHECK `epds_citizenship_kind_chk`). A record with no answer here reads
     * its legacy `employee_profiles.citizenship` text instead
     * (citizenshipText()).
     *
     * @var list<array{value: string, label: string}>
     */
    public const array CITIZENSHIP = [
        ['value' => 'FILIPINO', 'label' => 'Filipino'],
        ['value' => 'DUAL', 'label' => 'Dual citizenship'],
    ];

    /**
     * How a dual citizenship was acquired (`dual_citizenship_by`, CHECK
     * `epds_dual_by_chk`), asked only while Dual citizenship is picked.
     *
     * @var list<array{value: string, label: string}>
     */
    public const array DUAL_CITIZENSHIP_BY = [
        ['value' => 'BIRTH', 'label' => 'By birth'],
        ['value' => 'NATURALIZATION', 'label' => 'By naturalization'],
    ];

    /**
     * The seven parts of an address, column => label, in the order a line
     * reads them (addressLine()). Each is a column of `employee_addresses`.
     *
     * @var array<string, string>
     */
    public const array ADDRESS_PARTS = [
        'house_no' => 'House/Block/Lot No.',
        'street' => 'Street',
        'subdivision' => 'Subdivision/Village',
        'barangay' => 'Barangay',
        'city' => 'City/Municipality',
        'province' => 'Province',
        'zip_code' => 'ZIP code',
    ];

    /**
     * The form key prefix of each address type: `res_street`, `perm_zip_code`.
     *
     * @var array<string, string>
     */
    public const array ADDRESS_PREFIX = ['RESIDENTIAL' => 'res_', 'PERMANENT' => 'perm_'];

    /**
     * The accessible name of each part's input. Both groups share the labels
     * "Street", "ZIP code" …, so a screen reader needs the group in the name.
     *
     * @var array<string, string>
     */
    private const array ADDRESS_ARIA = [
        'house_no' => 'house/block/lot no.',
        'street' => 'street',
        'subdivision' => 'subdivision/village',
        'barangay' => 'barangay',
        'city' => 'city/municipality',
        'province' => 'province',
        'zip_code' => 'ZIP code',
    ];

    /**
     * The eight ABO/Rh groups the 201 sheet's "Blood type" line takes.
     * Stored as shown, "AB-" the longest.
     *
     * @var list<array{value: string, label: string}>
     */
    public const array BLOOD_TYPES = [
        ['value' => 'A+', 'label' => 'A+'],
        ['value' => 'A-', 'label' => 'A−'],
        ['value' => 'B+', 'label' => 'B+'],
        ['value' => 'B-', 'label' => 'B−'],
        ['value' => 'AB+', 'label' => 'AB+'],
        ['value' => 'AB-', 'label' => 'AB−'],
        ['value' => 'O+', 'label' => 'O+'],
        ['value' => 'O-', 'label' => 'O−'],
    ];

    /**
     * The one `children` field: at most this many rows — well above the
     * Personal Data Sheet's thirteen lines; `employee_children.position` is
     * a tinyint — each name no longer than the `employee_children.name`
     * column.
     */
    public const int MAX_CHILDREN = 30;

    public const int CHILD_NAME_CAP = 120;

    /**
     * The Educational Background levels in sheet order, stored value => the
     * dialog's label; EDUCATION_LEVEL_SHORT is the table cell's.
     *
     * @var array<string, string>
     */
    public const array EDUCATION_LEVELS = [
        'ELEMENTARY' => 'Elementary',
        'SECONDARY' => 'Secondary',
        'VOCATIONAL' => 'Vocational / trade course',
        'COLLEGE' => 'College',
        'GRADUATE' => 'Graduate studies',
    ];

    /** @var array<string, string> */
    public const array EDUCATION_LEVEL_SHORT = [
        'ELEMENTARY' => 'Elementary',
        'SECONDARY' => 'Secondary',
        'VOCATIONAL' => 'Vocational',
        'COLLEGE' => 'College',
        'GRADUATE' => 'Graduate',
    ];

    /**
     * A declaration's answer: Not answered (stored NULL), Yes (true) or No
     * (false). NULL is NEVER read as No.
     *
     * @var list<array{value: string, label: string}>
     */
    public const array ANSWERS = [
        ['value' => '', 'label' => 'Not answered'],
        ['value' => 'yes', 'label' => 'Yes'],
        ['value' => 'no', 'label' => 'No'],
    ];

    /**
     * A previous job's "Gov't service": unanswered, Y or N.
     *
     * @var list<array{value: string, label: string}>
     */
    public const array GOV_SERVICE = [
        ['value' => '', 'label' => '—'],
        ['value' => 'Y', 'label' => 'Y'],
        ['value' => 'N', 'label' => 'N'],
    ];

    /** The configured current employer; stored historical company names stay unchanged. */
    public static function companyName(): string
    {
        return (string) config('copems.company_name');
    }

    /**
     * The three Other info lists: at most MAX_ITEMS lines each, a line no
     * wider than `employee_other_items.text`. ITEM_KINDS maps each list's
     * field key to the kind its rows are stored under.
     */
    public const int MAX_ITEMS = 30;

    public const int ITEM_CAP = 255;

    /** @var array<string, string> */
    public const array ITEM_KINDS = [
        'skills' => EmployeeOtherItem::SKILL,
        'distinctions' => EmployeeOtherItem::DISTINCTION,
        'memberships' => EmployeeOtherItem::MEMBERSHIP,
    ];

    /**
     * The two stems the Declarations card prints above their questions,
     * keyed by the first question under each, worded as the Personal Data
     * Sheet words them.
     *
     * @var array<string, string>
     */
    public const array DECLARATION_STEMS = [
        'decl_34a' => 'Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,',
        'decl_40a' => "Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:",
    ];

    /**
     * The hire wizard's thirteen steps, in order: Personal, Addresses,
     * Emergency and Employee Details first, Government IDs & Bank last. A
     * step draws record sections (`sections`) or Personal Data Sheet lists
     * (`lists`); a list step may be left empty.
     *
     * @var list<array{id: string, title: string, sections?: list<string>, lists?: list<string>}>
     */
    public const array WIZARD = [
        ['id' => 'personal', 'title' => 'Personal Details', 'sections' => ['personal']],
        ['id' => 'addresses', 'title' => 'Addresses', 'sections' => ['addresses']],
        ['id' => 'emergency', 'title' => 'Emergency Contact', 'sections' => ['emergency']],
        ['id' => 'employment', 'title' => 'Employee Details', 'sections' => ['employment']],
        ['id' => 'family', 'title' => 'Family', 'sections' => ['spouse', 'parents', 'children']],
        ['id' => 'education', 'title' => 'Educational Background', 'lists' => ['education']],
        ['id' => 'eligibility', 'title' => 'Civil Service Eligibility', 'lists' => ['eligibility']],
        ['id' => 'experience', 'title' => 'Work Experience', 'lists' => ['experience']],
        ['id' => 'training', 'title' => 'Learning and Development', 'lists' => ['training']],
        ['id' => 'voluntary', 'title' => 'Voluntary Work', 'lists' => ['voluntary']],
        ['id' => 'other', 'title' => 'Other Information', 'sections' => ['skills', 'distinctions', 'memberships']],
        ['id' => 'declarations', 'title' => 'Declarations', 'sections' => ['declarations']],
        ['id' => 'statutory', 'title' => 'Government IDs & Bank', 'sections' => ['statutory']],
    ];

    /**
     * The hire wizard's steps (WIZARD).
     *
     * @return list<array{id: string, title: string, sections?: list<string>, lists?: list<string>}>
     */
    public static function wizard(): array
    {
        return self::WIZARD;
    }

    /**
     * employee-documents.tsx BUILT_IN_CATEGORIES — used only so documents
     * filed as `GOVERNMENT_ID` read as "Government ID".
     *
     * @var array<string, string>
     */
    public const array BUILT_IN_CATEGORIES = [
        'GOVERNMENT_ID' => 'Government ID',
        'CONTRACT' => 'Contract',
        'CERTIFICATE' => 'Certificate',
        'MEDICAL' => 'Medical',
        'RESUME' => 'Résumé',
        'CASE' => 'Case',
        'OTHER' => 'Other',
    ];

    /**
     * The hire wizard's REQUIRED answers. DELIBERATE DIVERGENCE from the
     * reference's set (add-employee-dialog.tsx:31-49): a hire needs only who
     * the person is and how to reach them — the company's Employee ID, the
     * name, the position (and through it the department), the contact
     * number, the email, the date of birth and the civil status. Sex, the
     * emergency contact, the employment status, the worksite, the date
     * joined and the rates may all be left for later; CreateEmployee fills
     * in what the record cannot be saved without (the date joined, the
     * status). `employee_code` is assigned rather than typed.
     *
     * Two more are required outside this list (EmployeeInput::stepErrors):
     * the DEPARTMENT, on the step that asks for the position, and the
     * residential ADDRESS as a group — at least one of its seven parts. A
     * field carrying `requiredWhenShown` is required only while it is shown
     * (isRequired()).
     *
     * @var list<string>
     */
    public const array REQUIRED = [
        'employee_id_no', 'first_name', 'last_name', 'phone', 'email', 'birth_date',
        'civil_status', 'position',
    ];

    /**
     * Whether the hire wizard must have an answer for `$key`, given the rest
     * of the form: REQUIRED, or a `requiredWhenShown` field while it shows.
     *
     * @param  array<string, mixed>  $form
     */
    public static function isRequired(string $key, array $form): bool
    {
        $field = self::field($key);

        if ($field !== null && isset($field['requiredWhenShown'])) {
            return self::shown($field, $form);
        }

        return in_array($key, self::REQUIRED, true);
    }

    /**
     * SECTIONS, transcribed field for field from employee-fields.tsx:70-176.
     * `derived` names a computed read-only value; `source` pulls options from
     * a Records Management list; `site` from Site Settings; `choice` names the
     * form key that carries the answer when it is not the column's own
     * (Position, EmployeePosition). `auto` — system-
     * assigned, rendered disabled — is legal and set by NO FIELD: the
     * divergence below says why the minted code has none, and `UpdateEmployee`
     * and `partials/field` honour the flag so adding one is safe.
     *
     * DELIBERATE DIVERGENCE — `employee_id_no`, and NO FIELD FOR THE MINTED
     * CODE. The reference carries one identifier, the minted code, and labels
     * it "Employee ID" (employee-fields.tsx:72). This system carries two,
     * because the company's own employee number is assigned on paper, arrives
     * late and never exists for some staff. The minted code keeps the
     * guarantees (never null, unique, sortable) but gives up both the name and
     * the field: it is labelled plainly "ID" wherever it is shown, "Employee
     * ID" means the number a person is actually given, and the ID itself
     * is read off the Employee Records list, where it is the first column.
     *
     * A section field for it could only ever be a disabled box — nobody types
     * a minted id, and this list is also the hire wizard's step 1, which would
     * be showing a number `CreateEmployee` mints again inside the insert
     * transaction. There is no TypeScript counterpart to check either decision
     * against.
     *
     * DELIBERATE DIVERGENCE — NO "PAYROLL SETTINGS" SECTION. The reference
     * ends with one (employee-fields.tsx): the hourly rate override, the
     * four grouping codes, the contribution and withholding switches, the
     * fixed amounts, Percent Tax and the three contribution modes. The
     * record, the hire wizard and the employee CSV carry none of it:
     * they are payroll's, not the 201 file's. A new hire gets the column
     * defaults (every switch on, no fixed amount, no override, SEMI_MONTHLY)
     * — the standard computation — and a payroll officer changes an
     * employee's on their pay page under Records → Payroll Settings: the
     * hourly rate in place (UpdateEmployeePaySettings), the contribution and
     * withholding settings as dated, approved Payroll versions laid over the
     * columns payroll reads through EmployeePayContextQuery. A one-payroll
     * exception is still typed on the draft, where a typed figure (0.00
     * included) wins. The statutory columns and the four grouping codes stay
     * unwritten.
     *
     * `columns` sets a section's grid on the record page where its `column`
     * would otherwise decide it (left 1, right 2); the wizard is always two.
     *
     * `nullable` (an amount only) saves a blank as NULL instead of 0, for an
     * amount whose absence means something (EmployeeInput::blankAmount);
     * `unit` (a number only) is printed after the value in read mode, "178
     * cm"; `hint` is a line of help under the control while editing.
     *
     * DELIBERATE DIVERGENCE — THE REST OF THE PAPER 201 FILE AND THE
     * PERSONAL DATA SHEET, so the record page prints as either: the
     * telephone, religion, height, weight and blood type on Personal
     * Details; the bank's name under Government IDs & Bank; the Spouse in
     * parts, the Parents, the Children with their birthdates, the three
     * Other info lists (Skills and Hobbies, Distinctions, Memberships) and
     * the twelve Declarations. The reference record carries none of them
     * (migrations 2026_10_01_0001 and _0002, 2026_10_07_0001 to _0009). All
     * optional. Employee Records managers directly edit authorized records;
     * Profile edits need independent approval. The schools, eligibilities, previous
     * jobs, trainings and voluntary work are rows of their own, outside this
     * registry (PdsLists). The legacy Spouse's Name, Spouse's Employment,
     * College and Graduate Studies columns are read-only fallbacks, not
     * fields: no form writes them, and every reader shows them only while
     * nothing structured replaces them (legacySpouse(), spouseNameText(),
     * spouseEmploymentText(), educationFallback()). The sheet's license and
     * passport lines are NOT record fields: a license is kept on its
     * eligibility row, and a passport is a document, filed on the Documents
     * card with its number and expiry.
     *
     * `children` is the one field typed `children`: a LIST of rows
     * (`[['name' => …, 'birth_date' => 'YYYY-MM-DD'], …]`) rather than a
     * string, stored as `employee_children` rows in that order. The form
     * carries it as an array, blankForm() starts it empty, toForm() reads the
     * rows back, readValue() prints one line per child, EmployeeInput checks
     * and normalises it on its own (childrenError / children), and the
     * commands write the rows beside the profile, child N on row N
     * (EmployeeDetailRows::replaceChildren).
     *
     * `lines` (with `'store' => 'items'` and its `kind`) is a short list of
     * text lines — an Other info list — carried in the form as
     * `list<string>` and stored as `employee_other_items` rows in order
     * (itemLines(), EmployeeInput::linesError() / lines(),
     * EmployeeDetailRows::replaceItems()). `answer` is a declaration: the
     * form value '' | 'yes' | 'no', stored NULL | true | false — NEVER read
     * through asBool(), because NULL is not No (answerForm(),
     * answerValue(), declarationText()); its details are ordinary fields
     * with `when` => [answer key, 'yes'].
     *
     * WHERE A VALUE LIVES. A field with no `store` is an `employee_profiles`
     * column. `'store' => 'pds'` puts it in the employee's one
     * `employee_pds_details` row, under the same name; `'store' => 'address'`
     * puts it in the `employee_addresses` row of its `address` type, in the
     * column `part` — the form keys are `res_<part>` and `perm_<part>`.
     * storedValue() reads all three; EmployeeInput::split() divides a payload
     * the same way for the commands.
     *
     * The other spec keys: `when` => [key, expected] shows, checks and
     * requires a field only while the form's `key` holds `expected` (a
     * boolean compares through asBool(), a string trimmed); a hidden field is
     * saved as NULL, and it never reads as a row of its own — its value is
     * folded into its parent's read text. `requiredWhenShown` is the message
     * when such a field is shown and blank. `default` is a switch's starting
     * answer in an edit form while nothing is stored. `metres` types and shows
     * a height in metres while the column holds centimetres. `rule` => 'zip'
     * takes exactly four digits. `inputmode` is forwarded to the input.
     * `legacy` names the stored legacy text an edit form shows beside a blank
     * answer. `num` reads a value in the `num` face. `aria` is an input's
     * accessible name when its label alone is ambiguous. `group` ('father' |
     * 'mother') groups the Parents fields. `rule` => 'year' takes a
     * four-digit year.
     *
     * Section keys: `hr` marks a section only Employee Records all-data may
     * edit (the record page draws its Edit for nobody else); `description`
     * is the line a card prints under its title.
     *
     * The legacy one-line `employee_profiles.address` and `citizenship` are
     * not fields: no form writes them. They are read only as fallbacks, while
     * the structured value is blank (residentialText(), citizenshipText()).
     *
     * The list is pure — no config, clock or database read — and built once
     * per process: isRequired() and field() ask for it once per field.
     *
     * @return list<array{id: string, title: string, column: string, columns?: int, hr?: bool, description?: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return self::$sections ??= [
            [
                'id' => 'personal',
                'title' => 'Personal Details',
                'column' => 'left',
                'fields' => [
                    ['key' => 'employee_id_no', 'label' => 'Employee ID'],
                    ['key' => 'first_name', 'label' => 'First Name'],
                    ['key' => 'middle_name', 'label' => 'Middle Name'],
                    ['key' => 'last_name', 'label' => 'Last Name'],
                    ['key' => 'suffix', 'label' => 'Suffix'],
                    ['key' => 'phone', 'label' => 'Mobile no.', 'type' => 'tel'],
                    // The landline of the sheet's "Tel. No." line, beside the
                    // mobile number above; the same PhoneRule.
                    ['key' => 'telephone', 'label' => 'Telephone Number', 'type' => 'tel'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email'],
                    ['key' => 'birth_date', 'label' => 'Date of Birth', 'type' => 'date'],
                    ['key' => 'age', 'label' => 'Age', 'derived' => 'age'],
                    // DELIBERATE DIVERGENCE — Place of Birth and the
                    // structured citizenship have no counterpart: the
                    // reference record carries neither. Optional. The legacy
                    // one-line address and citizenship text are read-only
                    // fallbacks, not fields (legacyAddress(),
                    // legacyCitizenship()); the address is typed in parts on
                    // the Addresses section.
                    ['key' => 'place_of_birth', 'label' => 'Place of Birth'],
                    ['key' => 'sex', 'label' => 'Sex at birth', 'type' => 'select', 'options' => self::SEX],
                    ['key' => 'civil_status', 'label' => 'Civil Status', 'type' => 'select', 'options' => self::CIVIL],
                    [
                        'key' => 'civil_status_other', 'label' => 'Specify', 'store' => 'pds',
                        'when' => ['civil_status', 'Other'], 'requiredWhenShown' => 'Specify the civil status.',
                    ],
                    [
                        'key' => 'citizenship_kind', 'label' => 'Citizenship', 'type' => 'select',
                        'options' => self::CITIZENSHIP, 'store' => 'pds', 'legacy' => 'citizenship',
                    ],
                    [
                        'key' => 'dual_citizenship_by', 'label' => 'Dual citizenship', 'type' => 'select',
                        'options' => self::DUAL_CITIZENSHIP_BY, 'store' => 'pds', 'when' => ['citizenship_kind', 'DUAL'],
                        'requiredWhenShown' => 'Choose whether the dual citizenship is by birth or by naturalization.',
                    ],
                    [
                        'key' => 'dual_citizenship_country', 'label' => 'Country', 'store' => 'pds',
                        'when' => ['citizenship_kind', 'DUAL'], 'requiredWhenShown' => 'Name the country of the other citizenship.',
                    ],
                    // The sheet's Religion / Height / Weight / Blood type
                    // lines. The two measures are numbers so the sheet can
                    // print their units; blank stores NULL. The height is
                    // typed and shown in metres; the column and the sheet
                    // keep centimetres.
                    ['key' => 'religion', 'label' => 'Religion'],
                    [
                        'key' => 'height_cm', 'label' => 'Height', 'type' => 'number', 'unit' => 'm', 'metres' => true,
                        'nullable' => true, 'hint' => 'In metres, like 1.68.',
                    ],
                    ['key' => 'weight_kg', 'label' => 'Weight', 'type' => 'number', 'unit' => 'kg', 'nullable' => true, 'hint' => 'In kilograms.'],
                    ['key' => 'blood_type', 'label' => 'Blood Type', 'type' => 'select', 'options' => self::BLOOD_TYPES],
                    // DELIBERATE DIVERGENCE — the reference ends this section
                    // with "Residence Certificate" (employee-fields.tsx); this
                    // record has no such field. The
                    // `employee_profiles.residence_cert` column exists, but no
                    // screen, import or calculation reads or writes it.
                ],
            ],
            [
                // The residential address in parts, then whether the
                // permanent address is the same, then its own parts — asked
                // only while it is not. Each group is one `employee_addresses`
                // row; the hire requires at least one residential part
                // (EmployeeInput::stepErrors).
                'id' => 'addresses',
                'title' => 'Addresses',
                'column' => 'right',
                'columns' => 2,
                'fields' => [
                    ...self::addressFields('RESIDENTIAL', null),
                    ['key' => 'permanent_address_same', 'label' => 'Same as residential', 'type' => 'switch', 'store' => 'pds', 'default' => true],
                    ...self::addressFields('PERMANENT', ['permanent_address_same', false]),
                ],
            ],
            [
                'id' => 'emergency',
                'title' => 'Emergency Contact',
                'column' => 'right',
                // Two by two: Full Name | Relationship, then Contact Number |
                // Address — on the record's right column and in the wizard.
                'columns' => 2,
                'fields' => [
                    ['key' => 'emergency_name', 'label' => 'Full Name'],
                    ['key' => 'emergency_relation', 'label' => 'Relationship'],
                    ['key' => 'emergency_phone', 'label' => 'Contact Number', 'type' => 'tel'],
                    // DELIBERATE DIVERGENCE — no counterpart in the reference,
                    // like the two Personal Details fields above; optional.
                    ['key' => 'emergency_address', 'label' => 'Address', 'type' => 'textarea'],
                ],
            ],
            [
                'id' => 'employment',
                'title' => 'Employee Details',
                'column' => 'right',
                'fields' => [
                    // `choice`: the form answers this field under another key
                    // — the id of a position on the Position list, or
                    // EmployeePosition::KEEP — and EmployeePosition writes
                    // `position_id` with the name copied into `position`. The
                    // form's `position` stays the stored title, which the read
                    // view and the Employee Records CSV show.
                    ['key' => 'position', 'label' => 'Position', 'type' => 'select', 'source' => 'positions', 'choice' => EmployeePosition::FORM_KEY],
                    ['key' => 'position_level', 'label' => 'Position Level'],
                    ['key' => 'employment_status', 'label' => 'Employment Status', 'type' => 'select', 'source' => 'employment_statuses'],
                    ['key' => 'work_site_id', 'label' => 'Assigned Worksite', 'type' => 'select', 'site' => true],
                    ['key' => 'date_hired', 'label' => 'Date Joined', 'type' => 'date'],
                    ['key' => 'date_regular', 'label' => 'Date Regularized', 'type' => 'date'],
                    ['key' => 'date_resigned', 'label' => 'Date Resigned', 'type' => 'date'],
                    // KNOWN-GAP (ported deliberately): this switch is the ONLY
                    // path to deactivate an employee — the reference ships no
                    // dedicated deactivate action, and its add dialog hardcodes
                    // is_active=true. Kept faithful rather than adding one.
                    ['key' => 'is_active', 'label' => 'Active employee', 'type' => 'switch'],
                    ['key' => 'monthly_rate', 'label' => 'Rate Per Month', 'type' => 'money'],
                    // DELIBERATE DIVERGENCE — the reference derives every
                    // day's pay from Rate Per Month. When above zero this IS
                    // the pay per day (PayslipCalculator, divergence 10);
                    // blank stores NULL (`nullable`), not 0, and payroll
                    // derives the day from Rate Per Month as the reference does.
                    [
                        'key' => 'daily_rate', 'label' => 'Rate Per Day', 'type' => 'money', 'nullable' => true,
                        'hint' => 'Payroll pays this per day. Leave blank to work it out from the Rate Per Month.',
                    ],
                    // DELIBERATE DIVERGENCE — the reference carries
                    // "Allowances" here (employee-fields.tsx); this record
                    // does not: payroll never pays it (PayslipCalculator [R07]
                    // takes the allowance typed on the draft instead). The
                    // `employee_profiles.allowances` column exists, but no
                    // screen, import or calculation reads or writes it.
                    // DELIBERATE DIVERGENCE — the reference carries "Vacation
                    // Leave Credits" and "Sick Leave Credits" here
                    // (employee-fields.tsx:119-120); this record carries
                    // neither: nothing is computed from them, and leave is
                    // approved per request, not drawn down from a balance.
                    // Migration 2026_09_25_0002 drops the two
                    // `employee_profiles` columns. Credits live in
                    // `leave_credits` — one row per employee, Leave Settings
                    // type and year — entered on Leave Management's Leave
                    // Credits tab, never on the employee record.
                    ['key' => 'length_of_service', 'label' => 'Length Of Service', 'derived' => 'length_of_service'],
                ],
            ],
            [
                'id' => 'statutory',
                'title' => 'Government IDs & Bank',
                'column' => 'right',
                'fields' => [
                    // The government numbers in the order of the record
                    // card; the Employee Records CSV keeps its own frozen
                    // order. UMID and PhilSys live in the details row.
                    ['key' => 'sss_no', 'label' => 'SSS Number'],
                    ['key' => 'umid_no', 'label' => 'UMID ID no.', 'store' => 'pds'],
                    ['key' => 'pagibig_no', 'label' => 'Pag-IBIG Number'],
                    ['key' => 'philhealth_no', 'label' => 'PhilHealth Number'],
                    ['key' => 'philsys_pcn', 'label' => 'PhilSys card no.', 'store' => 'pds', 'num' => true],
                    ['key' => 'tin', 'label' => 'TIN'],
                    // The sheet's "Bank and Branch" line.
                    ['key' => 'bank_name', 'label' => 'Bank and Branch'],
                    ['key' => 'bank_account_no', 'label' => 'Account Number'],
                    ['key' => 'bank_code', 'label' => 'Bank Code (SWIFT)'],
                    ['key' => 'bank_type', 'label' => 'Bank Type'],
                ],
            ],
            [
                // The spouse in parts. The legacy Spouse's Name and Spouse's
                // Employment are read-only fallbacks, shown while no part is
                // typed (legacySpouse()).
                'id' => 'spouse',
                'title' => 'Spouse',
                'column' => 'right',
                'columns' => 2,
                'hr' => true,
                'fields' => [
                    ['key' => 'spouse_surname', 'label' => 'Surname', 'store' => 'pds', 'aria' => "Spouse's surname"],
                    ['key' => 'spouse_first_name', 'label' => 'First name', 'store' => 'pds', 'aria' => "Spouse's first name"],
                    ['key' => 'spouse_name_extension', 'label' => 'Name extension', 'store' => 'pds', 'aria' => "Spouse's name extension"],
                    ['key' => 'spouse_middle_name', 'label' => 'Middle name', 'store' => 'pds', 'aria' => "Spouse's middle name"],
                    ['key' => 'spouse_occupation', 'label' => 'Occupation', 'store' => 'pds', 'aria' => "Spouse's occupation"],
                    ['key' => 'spouse_employer', 'label' => 'Employer / business name', 'store' => 'pds', 'aria' => "Spouse's employer / business name"],
                    ['key' => 'spouse_business_address', 'label' => 'Business address', 'store' => 'pds', 'aria' => "Spouse's business address"],
                    ['key' => 'spouse_telephone', 'label' => 'Telephone no.', 'type' => 'tel', 'store' => 'pds', 'aria' => "Spouse's telephone no."],
                ],
            ],
            [
                // Read as one line each (fatherText(), motherText()); the
                // mother under her maiden name.
                'id' => 'parents',
                'title' => 'Parents',
                'column' => 'right',
                'columns' => 2,
                'hr' => true,
                'fields' => [
                    ['key' => 'father_surname', 'label' => 'Surname', 'store' => 'pds', 'group' => 'father', 'aria' => "Father's surname"],
                    ['key' => 'father_first_name', 'label' => 'First name', 'store' => 'pds', 'group' => 'father', 'aria' => "Father's first name"],
                    ['key' => 'father_name_extension', 'label' => 'Name extension', 'store' => 'pds', 'group' => 'father', 'aria' => "Father's name extension"],
                    ['key' => 'father_middle_name', 'label' => 'Middle name', 'store' => 'pds', 'group' => 'father', 'aria' => "Father's middle name"],
                    ['key' => 'mother_surname', 'label' => 'Surname', 'store' => 'pds', 'group' => 'mother', 'aria' => "Mother's maiden surname"],
                    ['key' => 'mother_first_name', 'label' => 'First name', 'store' => 'pds', 'group' => 'mother', 'aria' => "Mother's maiden first name"],
                    ['key' => 'mother_middle_name', 'label' => 'Middle name', 'store' => 'pds', 'group' => 'mother', 'aria' => "Mother's maiden middle name"],
                ],
            ],
            [
                // A family has any number of children, so the list spans the
                // card and saves on its own.
                'id' => 'children',
                'title' => 'Children',
                'column' => 'right',
                'hr' => true,
                'fields' => [
                    ['key' => 'children', 'label' => 'Children', 'type' => 'children', 'wide' => true],
                ],
            ],
            [
                'id' => 'skills',
                'title' => 'Skills and Hobbies',
                'column' => 'right',
                'columns' => 1,
                'hr' => true,
                'fields' => [
                    ['key' => 'skills', 'label' => 'Special skills and hobbies', 'type' => 'lines', 'store' => 'items', 'kind' => self::ITEM_KINDS['skills']],
                ],
            ],
            [
                'id' => 'distinctions',
                'title' => 'Distinctions',
                'column' => 'right',
                'columns' => 1,
                'hr' => true,
                'fields' => [
                    ['key' => 'distinctions', 'label' => 'Non-academic distinctions / recognition', 'type' => 'lines', 'store' => 'items', 'kind' => self::ITEM_KINDS['distinctions']],
                ],
            ],
            [
                'id' => 'memberships',
                'title' => 'Memberships',
                'column' => 'right',
                'columns' => 1,
                'hr' => true,
                'fields' => [
                    ['key' => 'memberships', 'label' => 'Membership in association / organization', 'type' => 'lines', 'store' => 'items', 'kind' => self::ITEM_KINDS['memberships']],
                ],
            ],
            [
                'id' => 'declarations',
                'title' => 'Declarations',
                'description' => 'Answered Yes or No; details are kept only for a Yes.',
                'column' => 'right',
                'columns' => 2,
                'hr' => true,
                'fields' => self::declarationFields(),
            ],
        ];
    }

    /**
     * The twelve declarations in sheet order: each question as the Personal
     * Data Sheet words it, whether it spans the card, and the details it
     * keeps for a Yes.
     *
     * @var array<string, array{label: string, wide: bool, details: list<array{key: string, label: string, type?: string}>}>
     */
    private const array DECLARATIONS = [
        'decl_34a' => [
            'label' => 'within the third degree?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_34a_details', 'label' => 'Details'],
            ],
        ],
        'decl_34b' => [
            'label' => 'within the fourth degree (for Local Government Unit - Career Employees)?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_34b_details', 'label' => 'Details'],
            ],
        ],
        'decl_35a' => [
            'label' => 'Have you ever been found guilty of any administrative offense?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_35a_details', 'label' => 'Details'],
            ],
        ],
        'decl_35b' => [
            'label' => 'Have you been criminally charged before any court?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_35b_details', 'label' => 'Details'],
                ['key' => 'decl_35b_date_filed', 'label' => 'Date filed', 'type' => 'date'],
                ['key' => 'decl_35b_case_status', 'label' => 'Status of case/s'],
            ],
        ],
        'decl_36' => [
            'label' => 'Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?',
            'wide' => true,
            'details' => [
                ['key' => 'decl_36_details', 'label' => 'Details'],
            ],
        ],
        'decl_37' => [
            'label' => 'Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?',
            'wide' => true,
            'details' => [
                ['key' => 'decl_37_details', 'label' => 'Details'],
            ],
        ],
        'decl_38a' => [
            'label' => 'Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_38a_details', 'label' => 'Details'],
            ],
        ],
        'decl_38b' => [
            'label' => 'Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_38b_details', 'label' => 'Details'],
            ],
        ],
        'decl_39' => [
            'label' => 'Have you acquired the status of an immigrant or permanent resident of another country?',
            'wide' => true,
            'details' => [
                ['key' => 'decl_39_country', 'label' => 'Country'],
            ],
        ],
        'decl_40a' => [
            'label' => 'Are you a member of any indigenous group?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_40a_specify', 'label' => 'Specify'],
            ],
        ],
        'decl_40b' => [
            'label' => 'Are you a person with disability?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_40b_id_no', 'label' => 'ID No.'],
            ],
        ],
        'decl_40c' => [
            'label' => 'Are you a solo parent?',
            'wide' => false,
            'details' => [
                ['key' => 'decl_40c_id_no', 'label' => 'ID No.'],
            ],
        ],
    ];

    /**
     * The declarations as fields, each answer followed by its details. A
     * detail is optional: a Yes with no details is still an answer. Its
     * accessible name carries its question, because twelve inputs share the
     * label "Details".
     *
     * @return list<array<string, mixed>>
     */
    private static function declarationFields(): array
    {
        $fields = [];

        foreach (self::DECLARATIONS as $key => $question) {
            $answer = ['key' => $key, 'label' => $question['label'], 'type' => 'answer', 'options' => self::ANSWERS, 'store' => 'pds'];

            if ($question['wide']) {
                $answer['wide'] = true;
            }

            $fields[] = $answer;

            foreach ($question['details'] as $detail) {
                $field = [
                    'key' => $detail['key'], 'label' => $detail['label'], 'store' => 'pds', 'when' => [$key, 'yes'],
                    'aria' => mb_substr($detail['label'].' — '.$question['label'], 0, 60),
                ];

                if (isset($detail['type'])) {
                    $field['type'] = $detail['type'];
                }

                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Every non-derived field, flat (ALL_FIELDS in the source).
     *
     * @return list<array<string, mixed>>
     */
    public static function allFields(): array
    {
        $fields = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if (! isset($field['derived'])) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /** @return array<string, mixed>|null */
    public static function section(string $id): ?array
    {
        foreach (self::sections() as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        return null;
    }

    /** @var list<array{id: string, title: string, column: string, columns?: int, hr?: bool, description?: string, fields: list<array<string, mixed>>}>|null */
    private static ?array $sections = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $fieldIndex = null;

    /**
     * The seven parts of one address type as fields, in ADDRESS_PARTS order,
     * each asked only while `$when` holds.
     *
     * @param  array{0: string, 1: bool}|null  $when
     * @return list<array<string, mixed>>
     */
    private static function addressFields(string $type, ?array $when): array
    {
        $group = $type === 'RESIDENTIAL' ? 'Residential' : 'Permanent';
        $fields = [];

        foreach (self::ADDRESS_PARTS as $part => $label) {
            $field = [
                'key' => self::ADDRESS_PREFIX[$type].$part, 'label' => $label, 'aria' => $group.' '.self::ADDRESS_ARIA[$part],
                'store' => 'address', 'address' => $type, 'part' => $part,
            ];

            if ($part === 'zip_code') {
                $field += ['rule' => 'zip', 'inputmode' => 'numeric'];
            }

            if ($when !== null) {
                $field['when'] = $when;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * The spec of one non-derived field, so a rule about one key never walks
     * the whole registry.
     *
     * @return array<string, mixed>|null
     */
    public static function field(string $key): ?array
    {
        if (self::$fieldIndex === null) {
            $index = [];

            foreach (self::allFields() as $field) {
                $index[(string) $field['key']] = $field;
            }

            self::$fieldIndex = $index;
        }

        return self::$fieldIndex[$key] ?? null;
    }

    /**
     * Whether a field is asked for, given the rest of the form: always,
     * unless its `when` names another answer it waits on (Specify while Civil
     * Status is Other, the permanent parts while the address is not the same
     * as the residential one). A missing answer reads as that field's
     * `default`.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $form
     */
    public static function shown(array $field, array $form): bool
    {
        $when = $field['when'] ?? null;

        if (! is_array($when)) {
            return true;
        }

        [$key, $expected] = $when;
        $key = (string) $key;
        $value = $form[$key] ?? (self::field($key)['default'] ?? '');

        if (is_bool($expected)) {
            return self::asBool($value) === $expected;
        }

        return self::textOf($value) === $expected;
    }

    /**
     * What the record stores for a field, wherever it lives (see `store` on
     * sections()). A detached view carries every value flat under its form
     * key — the details row and the addresses only when it was projected
     * with them, so asking a view without them for one fails loudly. A model
     * reads its relations, loading one only while its table exists. Nothing
     * stored reads as NULL; a switch's `default` belongs to the edit form,
     * never to this.
     *
     * @param  array<string, mixed>  $field
     */
    public static function storedValue(EmployeeProfile|EmployeeProfileView $employee, array $field): mixed
    {
        $key = (string) $field['key'];
        $store = $field['store'] ?? null;

        if ($store === null) {
            return $employee->getAttribute($key);
        }

        if ($store === 'items') {
            return self::itemLines($employee, $key);
        }

        if ($employee instanceof EmployeeProfileView) {
            self::needDetails($employee);

            return $employee->getAttribute($key);
        }

        if ($store === 'pds') {
            return self::detailsRow($employee)?->getAttribute($key);
        }

        foreach (self::addressRows($employee) as $row) {
            if ($row->type === ($field['address'] ?? null)) {
                return $row->getAttribute((string) ($field['part'] ?? ''));
            }
        }

        return null;
    }

    /**
     * A height stored in centimetres as metres, the way it is typed: up to
     * four decimals so every DECIMAL(5,2) centimetre value round-trips
     * exactly (162.5 → "1.625", 178 → "1.78").
     */
    public static function metresText(float $cm): string
    {
        return rtrim(rtrim(number_format($cm / 100, 4, '.', ''), '0'), '.');
    }

    /**
     * One address type's seven parts as stored, part => trimmed text ('' when
     * blank).
     *
     * @return array<string, string>
     */
    public static function addressParts(EmployeeProfile|EmployeeProfileView $employee, string $type): array
    {
        self::needDetails($employee);
        $parts = [];

        foreach (array_keys(self::ADDRESS_PARTS) as $part) {
            $field = self::field((self::ADDRESS_PREFIX[$type] ?? '').$part);
            $parts[$part] = $field === null ? '' : self::textOf(self::storedValue($employee, $field));
        }

        return $parts;
    }

    /**
     * An address as one line: the filled parts from the house number to the
     * province, comma-separated, then the ZIP code ("Blk 5 Lot 2, Rizal St.,
     * Tayabas City, Quezon 4327"); '' when every part is blank.
     *
     * @param  array<string, string>  $parts
     */
    public static function addressLine(array $parts): string
    {
        $filled = [];

        foreach (array_keys(self::ADDRESS_PARTS) as $part) {
            $text = trim($parts[$part] ?? '');

            if ($part !== 'zip_code' && $text !== '') {
                $filled[] = $text;
            }
        }

        return trim(implode(', ', $filled).' '.trim($parts['zip_code'] ?? ''));
    }

    /**
     * Why an Addresses save may not blank every residential part, or null.
     * The legacy one-line address is never written, so clearing the typed
     * parts cannot clear it: it would come back into view, and be printed and
     * exported, as the current address. The refusal applies only while typed
     * parts are stored and the save blanks them all; a record that has only
     * the legacy address saves blank parts without a word, and so does one
     * that never had a legacy address.
     *
     * @param  array<string, mixed>  $form
     */
    public static function keptLegacyAddressError(EmployeeProfile|EmployeeProfileView $employee, array $form): ?string
    {
        if (trim((string) $employee->address) === '' || self::addressLine(self::addressParts($employee, 'RESIDENTIAL')) === '') {
            return null;
        }

        foreach (array_keys(self::ADDRESS_PARTS) as $part) {
            if (self::textOf($form[self::ADDRESS_PREFIX['RESIDENTIAL'].$part] ?? '') !== '') {
                return null;
            }
        }

        return 'Residential address needs at least one part — the address entered before is kept until it is replaced.';
    }

    /** The stored one-line address while no residential part is typed, else ''. */
    public static function legacyAddress(EmployeeProfile|EmployeeProfileView $employee): string
    {
        $legacy = trim((string) $employee->address);

        return $legacy !== '' && self::addressLine(self::addressParts($employee, 'RESIDENTIAL')) === '' ? $legacy : '';
    }

    /**
     * The residential address as one line: the typed parts, else the stored
     * one-line address; '' when neither. Clearing every typed part brings the
     * stored line back into view, because it is never removed.
     */
    public static function residentialText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        $line = self::addressLine(self::addressParts($employee, 'RESIDENTIAL'));

        return $line !== '' ? $line : self::legacyAddress($employee);
    }

    /**
     * The permanent address as one line: "Same as residential" once that is
     * the answer, else its typed parts; '' while nobody has answered.
     */
    public static function permanentText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        self::needDetails($employee);

        if (self::storedValue($employee, self::spec('permanent_address_same')) === true) {
            return 'Same as residential';
        }

        return self::addressLine(self::addressParts($employee, 'PERMANENT'));
    }

    /** The stored citizenship text while no structured citizenship is chosen, else ''. */
    public static function legacyCitizenship(EmployeeProfile|EmployeeProfileView $employee): string
    {
        self::needDetails($employee);

        return self::textOf(self::storedValue($employee, self::spec('citizenship_kind'))) === ''
            ? trim((string) $employee->citizenship)
            : '';
    }

    /**
     * The citizenship as one line: "Filipino", or "Dual citizenship · by
     * naturalization · Japan", its parts set apart by middle dots; the
     * stored legacy text while none is chosen. "By birth" or "by
     * naturalization" belongs to a dual citizenship only, so a Filipino
     * reads "Filipino" alone.
     */
    public static function citizenshipText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        $kind = self::textOf(self::storedValue($employee, self::spec('citizenship_kind')));

        if ($kind === '') {
            return self::legacyCitizenship($employee);
        }

        if ($kind !== 'DUAL') {
            return self::optionLabel(self::CITIZENSHIP, $kind);
        }

        $by = self::textOf(self::storedValue($employee, self::spec('dual_citizenship_by')));
        $country = self::textOf(self::storedValue($employee, self::spec('dual_citizenship_country')));
        $text = 'Dual citizenship';

        if ($by !== '') {
            $text .= ' · '.lcfirst(self::optionLabel(self::DUAL_CITIZENSHIP_BY, $by));
        }

        return $country === '' ? $text : $text.' · '.$country;
    }

    /**
     * The civil status as read: "Other — <specification>" ("Other/s" while
     * none is given), any other stored value as its option label, or as
     * stored when it is off the list; '' when blank.
     */
    public static function civilStatusText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        self::needDetails($employee);
        $status = self::textOf($employee->civil_status);

        if ($status === 'Other') {
            $specify = self::textOf(self::storedValue($employee, self::spec('civil_status_other')));

            return $specify === '' ? 'Other/s' : 'Other — '.$specify;
        }

        return $status === '' ? '' : self::optionLabel(self::CIVIL, $status);
    }

    /**
     * Whether `$field` is a declaration's answer (see `answer` on sections()).
     *
     * @param  array<string, mixed>  $field
     */
    public static function isAnswer(array $field): bool
    {
        return ($field['type'] ?? null) === 'answer';
    }

    /**
     * Whether `$field` is an Other info list of lines (see `lines` on sections()).
     *
     * @param  array<string, mixed>  $field
     */
    public static function isLines(array $field): bool
    {
        return ($field['type'] ?? null) === 'lines';
    }

    /** A stored answer as the form's select holds it: NULL → '', true → 'yes', false → 'no'. */
    public static function answerForm(mixed $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        return (bool) $stored ? 'yes' : 'no';
    }

    /** The form's answer as stored: 'yes' → true, 'no' → false, anything else → NULL (Not answered). */
    public static function answerValue(string $form): ?bool
    {
        return match ($form) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * A calendar day as read ("04 May 2019"), from a date cast (a Carbon on
     * a model and on a view alike) or a `YYYY-MM-DD` key; '' for anything
     * else, NULL and blank included.
     */
    public static function dateText(mixed $value): string
    {
        $key = $value instanceof CarbonInterface ? $value->format('Y-m-d') : self::textOf($value);

        return self::isCalendarDay($key) ? DateText::toDisplay($key) : '';
    }

    /**
     * A name as the Family tab reads it, surname first: "Dela Cruz, Pedro
     * Ramos Sr."; blank parts skipped, '' when every part is blank.
     */
    public static function nameLine(string $surname, string $first, string $middle, string $ext = ''): string
    {
        $surname = trim($surname);
        $rest = implode(' ', array_filter([trim($first), trim($middle), trim($ext)], static fn (string $part): bool => $part !== ''));

        return match (true) {
            $surname === '' => $rest,
            $rest === '' => $surname,
            default => $surname.', '.$rest,
        };
    }

    /** Whether any of the spouse's eight parts is typed. */
    public static function spouseTyped(EmployeeProfile|EmployeeProfileView $employee): bool
    {
        self::needDetails($employee);

        foreach (self::section('spouse')['fields'] ?? [] as $field) {
            if (self::textOf(self::storedValue($employee, $field)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored legacy Spouse's Name and Spouse's Employment as one line
     * ("Maria S. Dela Cruz · Teacher, DepEd") while no spouse part is typed,
     * else ''.
     */
    public static function legacySpouse(EmployeeProfile|EmployeeProfileView $employee): string
    {
        if (self::spouseTyped($employee)) {
            return '';
        }

        return implode(' · ', array_filter(
            [trim((string) $employee->spouse_name), trim((string) $employee->spouse_employment)],
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * The spouse's name in natural order ("Maria Santos Dela Cruz") once a
     * part is typed, else the stored legacy Spouse's Name — the 201 sheet's
     * and the CSV's "Spouse's Name".
     */
    public static function spouseNameText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        if (! self::spouseTyped($employee)) {
            return trim((string) $employee->spouse_name);
        }

        return implode(' ', array_filter(
            array_map(static fn (string $key): string => self::pdsText($employee, $key), ['spouse_first_name', 'spouse_middle_name', 'spouse_surname', 'spouse_name_extension']),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * The spouse's occupation and employer ("Teacher, DepEd") once a part is
     * typed, else the stored legacy Spouse's Employment.
     */
    public static function spouseEmploymentText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        if (! self::spouseTyped($employee)) {
            return trim((string) $employee->spouse_employment);
        }

        return implode(', ', array_filter(
            [self::pdsText($employee, 'spouse_occupation'), self::pdsText($employee, 'spouse_employer')],
            static fn (string $part): bool => $part !== '',
        ));
    }

    /** The father's name as one line, surname first; '' when no part is typed. */
    public static function fatherText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return self::nameLine(
            self::pdsText($employee, 'father_surname'), self::pdsText($employee, 'father_first_name'),
            self::pdsText($employee, 'father_middle_name'), self::pdsText($employee, 'father_name_extension'),
        );
    }

    /** The mother's maiden name as one line, surname first; '' when no part is typed. */
    public static function motherText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return self::nameLine(
            self::pdsText($employee, 'mother_surname'), self::pdsText($employee, 'mother_first_name'),
            self::pdsText($employee, 'mother_middle_name'),
        );
    }

    /**
     * One Other info list's lines in order: from the view's flattened list
     * (EmployeeProjection) — none on a view projected without it — or, for
     * a model, its `otherItems` rows of that kind, loaded on first use while
     * their table exists.
     *
     * @return list<string>
     */
    public static function itemLines(EmployeeProfile|EmployeeProfileView $employee, string $key): array
    {
        if ($employee instanceof EmployeeProfileView) {
            $lines = $employee->getAttribute($key);

            return is_array($lines) ? array_values(array_map(static fn (mixed $line): string => self::textOf($line), $lines)) : [];
        }

        $kind = self::ITEM_KINDS[$key] ?? null;

        if ($kind === null) {
            return [];
        }

        if (! $employee->relationLoaded('otherItems')) {
            if (! $employee->exists || ! PdsLists::ready('other')) {
                return [];
            }

            $employee->load('otherItems');
        }

        return array_values($employee->otherItems
            ->filter(static fn (EmployeeOtherItem $item): bool => $item->kind === $kind)
            ->sortBy('position')
            ->map(static fn (EmployeeOtherItem $item): string => $item->text)
            ->all());
    }

    /**
     * A declaration as read: `answer` NULL (Not answered), true or false, and
     * `text` "Not answered", "No", or "Yes" followed, one line each, by every
     * detail given for it ("Yes\nDetails: …\nDate filed: 12 Mar 2020").
     *
     * @return array{answer: ?bool, text: string}
     */
    public static function declarationText(EmployeeProfile|EmployeeProfileView $employee, string $key): array
    {
        $answer = self::answerValue(self::answerForm(self::storedValue($employee, self::spec($key))));

        if ($answer === null) {
            return ['answer' => null, 'text' => 'Not answered'];
        }

        if ($answer === false) {
            return ['answer' => false, 'text' => 'No'];
        }

        $text = 'Yes';

        foreach (self::allFields() as $field) {
            if (($field['when'][0] ?? null) !== $key) {
                continue;
            }

            $value = self::storedValue($employee, $field);
            $value = ($field['type'] ?? null) === 'date' ? self::dateText($value) : self::textOf($value);

            if ($value !== '') {
                $text .= "\n".$field['label'].': '.$value;
            }
        }

        return ['answer' => true, 'text' => $text];
    }

    /**
     * The employee's schools as stored rows (`id`, `level`, `school`,
     * `course`, `attended_from`, `attended_to`, `units_earned`,
     * `year_graduated`, `honors`): the view's `education` list, none on a
     * view projected without it, or a model's `educations` rows, loaded on
     * first use while their table exists.
     *
     * @return list<array<string, mixed>>
     */
    public static function educationRows(EmployeeProfile|EmployeeProfileView $employee): array
    {
        if ($employee instanceof EmployeeProfileView) {
            $rows = $employee->getAttribute('education');

            return is_array($rows) ? array_values($rows) : [];
        }

        if (! $employee->relationLoaded('educations')) {
            if (! $employee->exists || ! PdsLists::ready('education')) {
                return [];
            }

            $employee->load('educations');
        }

        return EmployeeProjection::educationRows($employee->educations);
    }

    /**
     * The College or Graduate Studies line of the 201 sheet and the CSV:
     * the latest school of that level (by year graduated, else attended to,
     * else attended from; on a tie the later stored), else the stored
     * legacy school, course and year, never written by any form.
     *
     * @return array{school: string, course: string, year: string}
     */
    public static function educationFallback(EmployeeProfile|EmployeeProfileView $employee, string $level): array
    {
        $latest = null;
        $latestYear = null;

        foreach (self::educationRows($employee) as $row) {
            if (($row['level'] ?? null) !== $level) {
                continue;
            }

            $year = (int) ($row['year_graduated'] ?? $row['attended_to'] ?? $row['attended_from'] ?? 0);

            if ($latest === null || $year >= $latestYear) {
                $latest = $row;
                $latestYear = $year;
            }
        }

        if ($latest !== null) {
            return [
                'school' => self::textOf($latest['school'] ?? null),
                'course' => self::textOf($latest['course'] ?? null),
                'year' => self::textOf($latest['year_graduated'] ?? null),
            ];
        }

        [$school, $course, $year] = $level === EmployeeEducation::COLLEGE
            ? ['college', 'college_course', 'college_year_graduated']
            : ['graduate_school', 'graduate_course', 'graduate_year_graduated'];

        return [
            'school' => trim((string) $employee->getAttribute($school)),
            'course' => trim((string) $employee->getAttribute($course)),
            'year' => trim((string) $employee->getAttribute($year)),
        ];
    }

    /** The typed residential parts as one line, with no legacy fallback: the Addresses card's line. */
    public static function structuredResidentialText(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return self::addressLine(self::addressParts($employee, 'RESIDENTIAL'));
    }

    /** A details-row field's stored value as trimmed text. */
    private static function pdsText(EmployeeProfile|EmployeeProfileView $employee, string $key): string
    {
        return self::textOf(self::storedValue($employee, self::spec($key)));
    }

    /**
     * Refuses a view projected without the details row and the addresses,
     * so a consumer that forgot to ask never shows the legacy address as if
     * no parts existed.
     */
    private static function needDetails(EmployeeProfile|EmployeeProfileView $employee): void
    {
        if ($employee instanceof EmployeeProfileView && $employee->getAttribute('pds_loaded') !== true) {
            throw new LogicException('Employee details were not loaded for this view.');
        }
    }

    /** @return array<string, mixed> */
    private static function spec(string $key): array
    {
        return self::field($key) ?? throw new LogicException("No employee field {$key}.");
    }

    /** @param  list<array{value: string, label: string}>  $options */
    private static function optionLabel(array $options, string $value): string
    {
        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return $value;
    }

    /** A scalar as trimmed text; anything else (NULL, an array, a boolean) as ''. */
    private static function textOf(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /** The model's details row, loaded on first use while its table exists. */
    private static function detailsRow(EmployeeProfile $employee): ?EmployeePdsDetail
    {
        if (! $employee->relationLoaded('pdsDetail')) {
            if (! $employee->exists || ! EmployeeDetailRows::tablesReady()) {
                return null;
            }

            $employee->load('pdsDetail');
        }

        return $employee->pdsDetail;
    }

    /**
     * The model's address rows, loaded on first use while their table exists.
     *
     * @return list<EmployeeAddress>
     */
    private static function addressRows(EmployeeProfile $employee): array
    {
        if (! $employee->relationLoaded('addresses')) {
            if (! $employee->exists || ! EmployeeDetailRows::tablesReady()) {
                return [];
            }

            $employee->load('addresses');
        }

        return array_values($employee->addresses->all());
    }

    /** @return list<string> keys typed number or money (NUMERIC in the source) */
    public static function numericKeys(): array
    {
        $keys = [];

        foreach (self::allFields() as $field) {
            if (in_array($field['type'] ?? null, ['number', 'money'], true)) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** @return list<string> keys typed switch (BOOLEAN in the source) */
    public static function booleanKeys(): array
    {
        $keys = [];

        foreach (self::allFields() as $field) {
            if (($field['type'] ?? null) === 'switch') {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** Collapsed into a single "Full Name" row outside edit mode. */
    public const array NAME_PARTS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    /**
     * blankForm() (add-employee-dialog.tsx:52-69): '' everywhere, booleans
     * true, then the sensible starting points. Each Personal Data Sheet list
     * starts as an empty `rows_<list>`: the wizard's inline rows, which are
     * not record fields (PdsLists).
     *
     * @return array<string, mixed>
     */
    public static function blankForm(): array
    {
        $boolean = self::booleanKeys();
        $form = [];

        foreach (self::allFields() as $field) {
            $key = (string) $field['key'];
            $form[$key] = match (true) {
                in_array($key, $boolean, true) => (bool) ($field['default'] ?? true),
                self::isChildren($field), self::isLines($field) => [],
                default => '',
            };
        }

        $form['employment_status'] = 'Probationary';
        $form['date_hired'] = self::todayKey();

        foreach (self::allFields() as $field) {
            if (! in_array($field['type'] ?? null, ['number', 'money'], true)) {
                continue;
            }
            // '0' as the source did, except that an amount whose blank means
            // "no value" (nullable: Rate Per Day, the two measures) starts
            // blank, and so does Rate Per Month, which is asked for.
            $form[(string) $field['key']] = ($field['nullable'] ?? false) === true || $field['key'] === 'monthly_rate' ? '' : '0';
        }

        foreach (PdsLists::KEYS as $list) {
            $form['rows_'.$list] = [];
        }

        $form[EmployeePosition::FORM_KEY] = '';

        return $form;
    }

    /**
     * toForm() (employee-fields.tsx:186-193): the record as editable strings
     * and booleans. Dates come out as the `YYYY-MM-DD` key the DateField binds.
     * Every value is read where it lives (storedValue()); a switch nobody has
     * answered starts at its `default`, and a height comes out in metres.
     *
     * @return array<string, mixed>
     */
    public static function toForm(EmployeeProfile|EmployeeProfileView $employee): array
    {
        $boolean = self::booleanKeys();
        $form = [];

        foreach (self::allFields() as $field) {
            $key = (string) $field['key'];

            if (self::isChildren($field)) {
                $form[$key] = self::childRows($employee);

                continue;
            }

            if (self::isLines($field)) {
                $form[$key] = self::itemLines($employee, $key);

                continue;
            }

            $value = self::storedValue($employee, $field);

            if (self::isAnswer($field)) {
                $form[$key] = self::answerForm($value);

                continue;
            }

            if (in_array($key, $boolean, true)) {
                $form[$key] = $value === null && isset($field['default']) ? (bool) $field['default'] : (bool) $value;
            } elseif ($value instanceof CarbonImmutable) {
                $form[$key] = $value->format('Y-m-d');
            } elseif ($value === null) {
                $form[$key] = '';
            } elseif (! empty($field['metres']) && is_numeric($value)) {
                $form[$key] = self::metresText((float) $value);
            } elseif (is_float($value)) {
                // String(25000) → "25000", not "25000.00" — the MoneyInput
                // settles the decimals itself.
                $form[$key] = $value == (int) $value ? (string) (int) $value : (string) $value;
            } else {
                $form[$key] = (string) $value;
            }
        }

        $form[EmployeePosition::FORM_KEY] = EmployeePosition::formValue($employee->getAttribute('position_id'), $employee->getAttribute('position'));

        return $form;
    }

    /** A form/select boolean however Livewire round-tripped it. */
    public static function asBool(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    /**
     * readValue() (employee-fields.tsx:242-259) — one field as display text.
     *
     * @param  array<string, mixed>  $field
     */
    public static function readValue(EmployeeProfile|EmployeeProfileView $employee, array $field): string
    {
        $key = (string) $field['key'];
        $type = $field['type'] ?? null;

        if (self::isChildren($field)) {
            $lines = [];
            foreach (self::childRows($employee) as $child) {
                $lines[] = $child['name'];
            }

            return $lines === [] ? '—' : implode("\n", $lines);
        }

        if (self::isLines($field)) {
            $lines = self::itemLines($employee, $key);

            return $lines === [] ? '—' : implode("\n", $lines);
        }

        if (self::isAnswer($field)) {
            return self::declarationText($employee, $key)['text'];
        }

        if ($key === 'civil_status') {
            $text = self::civilStatusText($employee);

            return $text === '' ? '—' : $text;
        }

        if ($key === 'citizenship_kind') {
            $text = self::citizenshipText($employee);

            return $text === '' ? '—' : $text;
        }

        $raw = self::storedValue($employee, $field);

        if ($type === 'switch') {
            // A switch with a form default and no stored answer has not been
            // answered (Same as residential): it reads blank, never Yes.
            if ($raw === null && isset($field['default'])) {
                return '—';
            }

            return $raw ? 'Yes' : 'No';
        }

        if ($raw === null || $raw === '') {
            return '—';
        }

        if (! empty($field['metres'])) {
            // 162.50 cm → "1.625 m". Before the unit branch below, which
            // would print the centimetres with a metre unit.
            return self::metresText((float) $raw).' m';
        }

        if ($type === 'number' && isset($field['unit'])) {
            // 178.00 → "178 cm", 62.50 → "62.5 kg".
            return self::measure((float) $raw).' '.$field['unit'];
        }

        if ($type === 'date') {
            return $raw instanceof CarbonImmutable
                ? DateText::toDisplay($raw->format('Y-m-d'))
                : DateText::toDisplay((string) $raw);
        }

        if ($type === 'number' || $type === 'money') {
            // The source also has a "N days" face here for keys containing
            // "credit", and this record has no such key (see sections()).
            return self::peso((float) $raw);
        }

        if ($type === 'select') {
            if ($key === 'employment_status') {
                return self::employmentStatusLabel((string) $raw);
            }

            foreach ((array) ($field['options'] ?? []) as $option) {
                if (($option['value'] ?? null) === $raw) {
                    return (string) $option['label'];
                }
            }

            return (string) $raw;
        }

        return (string) $raw;
    }

    /**
     * Which read-mode values render in the tabular `num` face (Field, employee-fields.tsx:333-341).
     *
     * @param  array<string, mixed>  $field
     */
    public static function isNumFace(array $field): bool
    {
        $type = $field['type'] ?? null;

        return in_array($type, ['number', 'money', 'date'], true)
            || str_ends_with((string) $field['key'], '_no')
            || ! empty($field['num']);
    }

    /**
     * Whether `$field` is the children's list — the one field that is not a string.
     *
     * @param  array<string, mixed>  $field
     */
    public static function isChildren(array $field): bool
    {
        return ($field['type'] ?? null) === 'children';
    }

    /**
     * The employee's children as form rows, in sheet order: from the
     * detached view's `children` list (EmployeeRecords) or, for a model, its
     * loaded `children` relation. Stored dates come back as `YYYY-MM-DD`
     * (or '' when absent), the shape the editor's date fields bind.
     *
     * @return list<array{name: string, birth_date: string}>
     */
    public static function childRows(EmployeeProfile|EmployeeProfileView $employee): array
    {
        $rows = [];

        // A view built by any other projection has no `children` at all.
        foreach ($employee->children ?? [] as $child) {
            $name = $child instanceof EmployeeChild ? $child->name : $child['name'];
            $born = $child instanceof EmployeeChild ? $child->birth_date : $child['birth_date'];
            $rows[] = [
                'name' => $name,
                'birth_date' => $born instanceof CarbonImmutable ? $born->format('Y-m-d') : (string) ($born ?? ''),
            ];
        }

        return $rows;
    }

    /** A measure as a person writes it: no trailing zeros, at most two decimals (178, 62.5). */
    public static function measure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public static function fullName(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return EmployeeName::full($employee);
    }

    /**
     * employeeName() (hris.ts:425-427): first + last, plus the suffix. See
     * {@see EmployeeName} for why this delegates instead of composing its own.
     */
    public static function employeeName(EmployeeProfile|EmployeeProfileView|null $employee): string
    {
        return EmployeeName::display($employee);
    }

    public static function initials(EmployeeProfile|EmployeeProfileView $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /** ageFrom() (employee-fields.tsx:224-236), on the Manila calendar. */
    public static function ageFrom(?CarbonImmutable $birth): string
    {
        if ($birth === null) {
            return '—';
        }

        $today = CarbonImmutable::parse(self::todayKey());
        $age = $today->year - $birth->year;

        if ($today->month < $birth->month || ($today->month === $birth->month && $today->day < $birth->day)) {
            $age--;
        }

        return $age >= 0 ? (string) $age : '—';
    }

    /** yearsAndMonths() (employee-fields.tsx:205-222). */
    public static function yearsAndMonths(?CarbonImmutable $from): string
    {
        if ($from === null) {
            return '—';
        }

        $to = CarbonImmutable::parse(self::todayKey());
        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);

        if ($to->day < $from->day) {
            $months--;
        }

        if ($months < 0) {
            return '—';
        }

        $y = intdiv($months, 12);
        $m = $months % 12;

        $parts = [];

        if ($y !== 0) {
            $parts[] = $y.' year'.($y === 1 ? '' : 's');
        }

        if ($m !== 0 || $y === 0) {
            $parts[] = $m.' month'.($m === 1 ? '' : 's');
        }

        return implode(' and ', $parts);
    }

    /** @param  array<string, mixed>  $field */
    public static function derivedValue(EmployeeProfile|EmployeeProfileView $employee, array $field): string
    {
        return match ($field['derived'] ?? null) {
            'age' => self::ageFrom($employee->birth_date),
            'length_of_service' => self::yearsAndMonths($employee->date_hired),
            default => '—',
        };
    }

    /** "Job Order", "JOB_ORDER" and "job order" are one status (hris.ts:380-386). */
    public static function statusKey(?string $value): string
    {
        $key = strtoupper(trim($value ?? ''));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /** employmentStatusLabel (hris.ts:389-390), shared through HrisFormat behind this wrapper. */
    public static function employmentStatusLabel(string $value): string
    {
        return HrisFormat::employmentStatus($value);
    }

    public static function isRegular(?string $value): bool
    {
        return self::statusKey($value) === 'REGULAR';
    }

    /** categoryLabel() (employee-documents.tsx:66): token → label, names echo back. */
    public static function categoryLabel(string $value): string
    {
        return self::BUILT_IN_CATEGORIES[$value] ?? $value;
    }

    /** Today's Manila calendar day, `YYYY-MM-DD` (hris.ts todayKey) — ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return ManilaClock::todayKey();
    }

    /** peso() (hris.ts:180-186): ₱ + en-PH grouping, always two decimals. */
    public static function peso(float $n): string
    {
        if (! is_finite($n)) {
            $n = 0.0;
        }

        return "\u{20B1}".number_format($n, 2);
    }

    /** humanSize() (attachments.ts:25-28), shared through HrisFormat — one spelling on every upload surface. */
    public static function humanSize(int $bytes): string
    {
        return HrisFormat::bytes($bytes);
    }

    /** emailError() (hris.ts:230-238) — blank is fine; required is the form's call. One rule, EmailRule, shared with Users. */
    public static function emailError(string $value): ?string
    {
        return EmailRule::error($value);
    }

    /**
     * Why a contact number is unacceptable, or null — PhoneRule, applied to
     * every `tel` field on every write surface. No TypeScript counterpart:
     * the reference stored the text as typed (DELIBERATE DIVERGENCE, see
     * PhoneRule). Blank is fine; required is the form's call.
     */
    public static function phoneError(string $value): ?string
    {
        return PhoneRule::error($value);
    }

    /**
     * The highest employee id + 1, through the one mint in CodeSequence — a
     * bare number. CODE_PREFIX is passed as the LEGACY prefix: an employee
     * row the conversion has not reached still reads `COP-00042`, and the
     * mint has to count it as forty-two or hand out a number that row
     * already holds. BestWorkflows also used `BWF-00042`: normalize that
     * spelling only for the scan, preserving every stored code unchanged.
     *
     * @param  array<array-key, string|null>  $codes
     */
    public static function nextEmployeeCode(array $codes): string
    {
        $codes = array_map(static function (?string $code): ?string {
            if ($code !== null && preg_match('/^BWF-(\d+)$/i', trim($code), $matches) === 1) {
                return self::CODE_PREFIX.$matches[1];
            }

            return $code;
        }, $codes);

        return CodeSequence::next(rtrim(self::CODE_PREFIX, '-'), $codes);
    }

    /**
     * compareText() (useSort.ts:33-40): case-insensitive byte order, blanks
     * last, shared through App\Support\TextSort. Byte order, never ICU
     * `Collator('en')`, so the Employee Records order does not depend on
     * whether ext-intl is loaded on a host the company does not control;
     * TextSort's docblock says why byte order wins. The Employee Records
     * table sorts in SQL through its twin, `SqlText::order`, which the two
     * must keep agreeing with.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, so no two lists order the same
        // text differently. Call sites and Blade imports reach it through this
        // wrapper; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }

    /** A real `YYYY-MM-DD` calendar day (lib/payroll-period.ts payrollDate). */
    public static function isCalendarDay(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * employeeDateError() (lib/employee-policy.ts) — the cross-field calendar
     * rules shared by create and update, message for message.
     *
     * @param  array{birth_date?: ?string, date_hired: string, date_regular?: ?string, date_resigned?: ?string}  $value
     */
    public static function employeeDateError(array $value, string $today): ?string
    {
        $birth = $value['birth_date'] ?? null;
        $regular = $value['date_regular'] ?? null;
        $resigned = $value['date_resigned'] ?? null;
        $hired = $value['date_hired'];

        foreach ([
            ['Birth date', $birth],
            ['Date joined', $hired],
            ['Date regularized', $regular],
            ['Date resigned', $resigned],
        ] as [$label, $date]) {
            if ($date !== null && $date !== '' && ! self::isCalendarDay($date)) {
                return "{$label} must be a real calendar date.";
            }
        }

        if ($birth && $birth > $today) {
            return 'Birth date cannot be in the future.';
        }

        if ($birth && $birth > $hired) {
            return 'Birth date cannot be after the date joined.';
        }

        if ($regular && $regular < $hired) {
            return 'Date regularized cannot be before the date joined.';
        }

        if ($resigned && $resigned < $hired) {
            return 'Date resigned cannot be before the date joined.';
        }

        if ($regular && $resigned && $regular > $resigned) {
            return 'Date regularized cannot be after the date resigned.';
        }

        return null;
    }

    /**
     * The per-field caps of `EmployeeInput` (src/lib/api/schemas.ts:11-82) —
     * transcribed so an overlong value fails on save the way the API's 400 does.
     * `employee_id_no` has no entry there (see the DELIBERATE DIVERGENCE on
     * sections()); its 50 matches the column, so the message arrives before the
     * database truncates or rejects. The same goes for `place_of_birth`,
     * `citizenship`, `emergency_address` and the 201 sheet's lines, capped
     * at their columns' widths.
     *
     * @var array<string, int>
     */
    public const array TEXT_CAPS = [
        // `employee_code` is capped for completeness only: it is minted, never
        // typed, so no form payload can carry it to EmployeeRules.
        'employee_code' => 50, 'employee_id_no' => 50, 'first_name' => 100, 'middle_name' => 100,
        'last_name' => 100, 'suffix' => 20, 'position' => 120, 'position_level' => 60,
        'employment_status' => 60, 'sex' => 20, 'civil_status' => 30,
        'address' => 300, 'phone' => 40, 'email' => 200,
        'emergency_name' => 120, 'emergency_relation' => 60, 'emergency_phone' => 40,
        'place_of_birth' => 120, 'citizenship' => 60, 'emergency_address' => 300,
        // The 201 sheet's lines, at their columns' widths. The four at 64 are
        // that wide for the table's row-size budget, not for the text
        // (migration 2026_10_01_0001 says why).
        'telephone' => 64, 'religion' => 64, 'blood_type' => 5,
        'spouse_name' => 120, 'spouse_employment' => 120,
        'college' => 150, 'college_course' => 120, 'college_year_graduated' => 64,
        'graduate_school' => 150, 'graduate_course' => 120, 'graduate_year_graduated' => 64,
        'sss_no' => 40, 'philhealth_no' => 40, 'pagibig_no' => 40, 'tin' => 40,
        'bank_account_no' => 60, 'bank_code' => 40, 'bank_type' => 40,
        'bank_name' => 150,
        // The details row and the address parts, at their columns' widths.
        // `address` and `citizenship` above are legacy columns no form
        // writes; their caps stay for any payload that names them.
        'civil_status_other' => 60, 'dual_citizenship_country' => 60, 'umid_no' => 40, 'philsys_pcn' => 40,
        'res_house_no' => 60, 'res_street' => 120, 'res_subdivision' => 120, 'res_barangay' => 120,
        'res_city' => 120, 'res_province' => 120, 'res_zip_code' => 10,
        'perm_house_no' => 60, 'perm_street' => 120, 'perm_subdivision' => 120, 'perm_barangay' => 120,
        'perm_city' => 120, 'perm_province' => 120, 'perm_zip_code' => 10,
        // The spouse, the parents and the declarations' details, at their
        // columns' widths.
        'spouse_surname' => 64, 'spouse_first_name' => 64, 'spouse_middle_name' => 64,
        'spouse_name_extension' => 10, 'spouse_occupation' => 120, 'spouse_employer' => 150,
        'spouse_business_address' => 255, 'spouse_telephone' => 40,
        'father_surname' => 64, 'father_first_name' => 64, 'father_middle_name' => 64, 'father_name_extension' => 10,
        'mother_surname' => 64, 'mother_first_name' => 64, 'mother_middle_name' => 64,
        'decl_34a_details' => 255, 'decl_34b_details' => 255, 'decl_35a_details' => 255,
        'decl_35b_details' => 255, 'decl_35b_case_status' => 255, 'decl_36_details' => 255,
        'decl_37_details' => 255, 'decl_38a_details' => 255, 'decl_38b_details' => 255,
        'decl_39_country' => 60, 'decl_40a_specify' => 120, 'decl_40b_id_no' => 40, 'decl_40c_id_no' => 40,
    ];

    /**
     * Zod numeric maxima (schemas.ts:50-79), minus the caps of the reference
     * fields this record does not carry: the two leave credits, Allowances
     * and the Payroll Settings amounts (see sections()).
     *
     * @var array<string, float>
     */
    public const array NUMERIC_CAPS = [
        'monthly_rate' => 99_999_999,
        // Rate Per Day has no schema of its own to transcribe.
        // Its cap keeps a whole period's pay inside the payslip's
        // DECIMAL(12,2) columns: the longest period the statutory tables
        // allow (2025-01-01 to 2026-12-31) holds 731 days, and 731 x
        // 9,999,999 is under 7.4 billion. The monthly cap over those 24
        // months is under 2.4 billion.
        'daily_rate' => 9_999_999,
        // Height and weight are DECIMAL(5,2): 999.99 at most, and nobody is
        // three metres tall or half a tonne.
        'height_cm' => 300,
        'weight_kg' => 500,
        // A previous job's monthly salary and a training's or voluntary
        // work's hours, inside their DECIMAL(12,2) and DECIMAL(6,2) columns.
        'monthly_salary' => 9_999_999.99,
        'hours' => 9_999.99,
    ];
}
