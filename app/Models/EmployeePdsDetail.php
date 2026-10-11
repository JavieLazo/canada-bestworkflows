<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Personal Data Sheet lines of one employee's 201 file that the record's
 * own columns do not hold: the civil-status specification, the structured
 * citizenship, the "same as residential" answer, the UMID and PhilSys
 * numbers, the spouse and the parents' names in parts, and the twelve
 * declarations with their details (migration 2026_10_07_0003).
 *
 * A row of its own rather than columns on `employee_profiles`, which stays as
 * it is (migration 2026_10_07_0001 says why). 0 or 1 row per employee: a
 * missing row reads as all blank. `permanent_address_same` is NULL until
 * someone answers it — true is "same as residential", false "its own parts".
 * Each `decl_*` answer is the same kind of answer: NULL not answered, true
 * Yes, false No — NULL is never read as No.
 * Written only by the Workforce commands that save the record, inside the
 * profile's transaction after its row is locked, and read only through
 * `EmployeeRecords`, behind the record's own visibility rule — so this model
 * carries no actor scope, like `EmployeeChild`. The row is updated in place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $civil_status_other
 * @property string|null $citizenship_kind
 * @property string|null $dual_citizenship_by
 * @property string|null $dual_citizenship_country
 * @property bool|null $permanent_address_same
 * @property string|null $umid_no
 * @property string|null $philsys_pcn
 * @property string|null $spouse_surname
 * @property string|null $spouse_first_name
 * @property string|null $spouse_middle_name
 * @property string|null $spouse_name_extension
 * @property string|null $spouse_occupation
 * @property string|null $spouse_employer
 * @property string|null $spouse_business_address
 * @property string|null $spouse_telephone
 * @property string|null $father_surname
 * @property string|null $father_first_name
 * @property string|null $father_middle_name
 * @property string|null $father_name_extension
 * @property string|null $mother_surname
 * @property string|null $mother_first_name
 * @property string|null $mother_middle_name
 * @property bool|null $decl_34a
 * @property string|null $decl_34a_details
 * @property bool|null $decl_34b
 * @property string|null $decl_34b_details
 * @property bool|null $decl_35a
 * @property string|null $decl_35a_details
 * @property bool|null $decl_35b
 * @property string|null $decl_35b_details
 * @property CarbonImmutable|null $decl_35b_date_filed
 * @property string|null $decl_35b_case_status
 * @property bool|null $decl_36
 * @property string|null $decl_36_details
 * @property bool|null $decl_37
 * @property string|null $decl_37_details
 * @property bool|null $decl_38a
 * @property string|null $decl_38a_details
 * @property bool|null $decl_38b
 * @property string|null $decl_38b_details
 * @property bool|null $decl_39
 * @property string|null $decl_39_country
 * @property bool|null $decl_40a
 * @property string|null $decl_40a_specify
 * @property bool|null $decl_40b
 * @property string|null $decl_40b_id_no
 * @property bool|null $decl_40c
 * @property string|null $decl_40c_id_no
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeePdsDetail extends BaseModel
{
    /** @var string */
    protected $table = 'employee_pds_details';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'civil_status_other',
        'citizenship_kind',
        'dual_citizenship_by',
        'dual_citizenship_country',
        'permanent_address_same',
        'umid_no',
        'philsys_pcn',
        'spouse_surname',
        'spouse_first_name',
        'spouse_middle_name',
        'spouse_name_extension',
        'spouse_occupation',
        'spouse_employer',
        'spouse_business_address',
        'spouse_telephone',
        'father_surname',
        'father_first_name',
        'father_middle_name',
        'father_name_extension',
        'mother_surname',
        'mother_first_name',
        'mother_middle_name',
        'decl_34a',
        'decl_34a_details',
        'decl_34b',
        'decl_34b_details',
        'decl_35a',
        'decl_35a_details',
        'decl_35b',
        'decl_35b_details',
        'decl_35b_date_filed',
        'decl_35b_case_status',
        'decl_36',
        'decl_36_details',
        'decl_37',
        'decl_37_details',
        'decl_38a',
        'decl_38a_details',
        'decl_38b',
        'decl_38b_details',
        'decl_39',
        'decl_39_country',
        'decl_40a',
        'decl_40a_specify',
        'decl_40b',
        'decl_40b_id_no',
        'decl_40c',
        'decl_40c_id_no',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'permanent_address_same' => 'boolean',
            'decl_34a' => 'boolean',
            'decl_34b' => 'boolean',
            'decl_35a' => 'boolean',
            'decl_35b' => 'boolean',
            'decl_35b_date_filed' => 'immutable_date:Y-m-d',
            'decl_36' => 'boolean',
            'decl_37' => 'boolean',
            'decl_38a' => 'boolean',
            'decl_38b' => 'boolean',
            'decl_39' => 'boolean',
            'decl_40a' => 'boolean',
            'decl_40b' => 'boolean',
            'decl_40c' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
