{{--
    The employee page's single-page 201 file: a modern, structured sheet in
    which compact labelled grids stand in for the typed paper form. The
    scoped Workforce view is the only data source.

    Legal paper remains local to this page so payslips keep their own size.
    Before printing, measure at the paper's real width and fit unusually long
    records into its height. NEVER truncate a field or drop a child to fit.
    Measurements also work before print media activates; afterprint restores
    the sheet for the next print and any subsequent Livewire update.

    Expects: $employee (EmployeeProfileView projected with its details, for
    the civil status, citizenship, home address and spouse lines, and with
    the stored schools for College and Graduate Studies), $siteName,
    $photoUrl.
--}}
@php
    use App\Livewire\Employees\Support\EmployeeSheet as Sheet;
    use App\Support\EmployeeName;

    $name = EmployeeName::full($employee);
    // All values are escaped here, including the e-mail before its break hints.
    $field = static fn (string $label, string $value, int $span = 1, bool $html = false): string => '<div class="field" style="grid-column:span '.$span.'"><dt>'.e($label).'</dt><dd>'.($html ? $value : e($value)).'</dd></div>';
@endphp
<style>
    @page { size: 8.5in 14in; margin: .5in; }
    .sheet-201 { display: none; }
    /* These rules are scoped to the hidden sheet so beforeprint can measure
       exactly the same typography regardless of the current screen width. */
    .sheet-201 {
        --foreground: #17212b;
        --muted-foreground: #56616d;
        --border: #cbd2d9;
        --muted: #f1f3f5;
        box-sizing: border-box;
        width: 7.5in;
        height: 12.9in;
        margin: 0 !important;
        padding: 0;
        background: #fff;
        color: var(--foreground);
        font: 9.5pt/1.3 Arial, Helvetica, sans-serif;
        text-transform: none;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    .sheet-201 *, .sheet-201 *::before, .sheet-201 *::after { box-sizing: border-box; }
    .sheet-201 p, .sheet-201 h1, .sheet-201 h2, .sheet-201 dl, .sheet-201 dd { margin: 0; }
    .sheet-201 .sheet-content { display: flex; flex-direction: column; width: 7.5in; min-height: 12.85in; margin-inline: auto; }
    .sheet-201 .sheet-header { display: grid; grid-template-columns: minmax(0, 1fr) 1.1in; gap: 18pt; align-items: start; padding-bottom: 13pt; border-bottom: 2pt solid var(--foreground); }
    .sheet-201 .brand { font-size: 8pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .sheet-201 .document-title { margin-top: 5pt; font-size: 25pt; line-height: 1.1; font-weight: 700; letter-spacing: -.035em; }
    .sheet-201 .position { margin-top: 4pt; color: var(--muted-foreground); font-size: 9pt; overflow-wrap: anywhere; }
    .sheet-201 .photo { display: block; width: 1.1in; height: 1.1in; max-width: none; object-fit: cover; border: .75pt solid var(--border); border-radius: 5pt; }
    .sheet-201 .photo.blank { display: flex; align-items: center; justify-content: center; color: var(--muted-foreground); font-size: 7pt; letter-spacing: .08em; text-transform: uppercase; }
    .sheet-201 .identity { padding: 3pt 0 5pt; border-bottom: .75pt solid var(--border); }
    .sheet-201 .employee-name { margin-top: 11pt; font-size: 17pt; line-height: 1.2; font-weight: 700; letter-spacing: -.02em; overflow-wrap: anywhere; }
    .sheet-201 .block { margin-top: 12pt; }
    .sheet-201 .block-title { display: flex; gap: 7pt; align-items: baseline; padding-bottom: 5pt; border-bottom: .75pt solid var(--foreground); font-size: 9pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .sheet-201 .block-number { color: var(--muted-foreground); font-size: 7pt; font-weight: 400; letter-spacing: 0; }
    .sheet-201 .fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0 12pt; }
    .sheet-201 .field { min-width: 0; padding: 6pt 0 4pt; border-bottom: .5pt solid var(--border); }
    .sheet-201 dt { color: var(--muted-foreground); font-size: 6.7pt; line-height: 1.25; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; }
    .sheet-201 dd { min-height: 12.4pt; margin-top: 3pt; overflow-wrap: anywhere; }
    .sheet-201 dd:empty::before, .sheet-201 .v:empty::before { content: "\00a0"; }
    .sheet-201 .kids { margin-top: 8pt; }
    .sheet-201 .kid-row { display: grid; grid-template-columns: minmax(0, 1fr); padding: 4pt 0; border-bottom: .5pt solid var(--border); }
    .sheet-201 .kid-head { padding: 5pt 0; color: var(--muted-foreground); font-size: 6.7pt; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; }
    .sheet-201 .v { min-width: 0; overflow-wrap: anywhere; }
    .sheet-201 .sheet-footer { display: flex; justify-content: space-between; gap: 12pt; margin-top: auto; padding-top: 12pt; color: var(--muted-foreground); font-size: 6.5pt; letter-spacing: .03em; }
    /* Reclaim spacing before scaling text on a densely filled record. */
    .sheet-201[data-compact] { font-size: 9pt; line-height: 1.2; }
    .sheet-201[data-compact] .sheet-header { padding-bottom: 8pt; }
    .sheet-201[data-compact] .block { margin-top: 8pt; }
    .sheet-201[data-compact] .field { padding: 4pt 0 3pt; }
    .sheet-201[data-compact] dd { margin-top: 2pt; min-height: 10.8pt; }
    .sheet-201[data-compact] .kid-row { padding-block: 3pt; }
    .sheet-201[data-compact] .kids { margin-top: 5pt; }
    .sheet-201[data-measuring] { display: block !important; position: fixed !important; inset: 0 auto auto 0; visibility: hidden; }
    @media print {
        .record-page > :not(.sheet-201) { display: none !important; }
        html { color-scheme: light !important; }
        html, body { background: #fff !important; }
        body:has(.sheet-201) { display: block !important; }
        body:has(.sheet-201) > :has(.sheet-201),
        body:has(.sheet-201) > :has(.sheet-201) > :has(.sheet-201) { max-width: none !important; margin: 0 !important; padding: 0 !important; }
        .record-page { margin: 0 !important; }
        .sheet-201 { display: block; break-inside: avoid; }
    }
</style>
<section class="sheet-201 record-print"
    x-data="{
        fit() {
            const content = this.$refs.content;
            content.style.zoom = '1';
            this.$el.removeAttribute('data-compact');
            this.$el.setAttribute('data-measuring', '');
            const available = this.$el.clientHeight - 2;
            if (content.getBoundingClientRect().height > available) this.$el.setAttribute('data-compact', '');
            let scale = 1;
            // Recheck the rendered result: fractional font and grid rounding
            // can add a few pixels after zoom, even with an exact ratio.
            for (let attempt = 0; attempt < 4; attempt++) {
                const height = content.getBoundingClientRect().height;
                if (height <= available) break;
                scale *= available / height * .995;
                content.style.zoom = String(scale);
            }
            this.$el.removeAttribute('data-measuring');
        }
    }"
    x-on:beforeprint.window="fit()"
    x-on:afterprint.window="$refs.content.style.zoom = '1'; $el.removeAttribute('data-compact')"
>
    <div class="sheet-content" x-ref="content">
        <header class="sheet-header">
            <div>
                <p class="brand">{{ config('copems.company_name') }}</p>
                <h1 class="document-title">Employee 201 file</h1>
                <p class="employee-name">{{ $name }}</p>
                <p class="position">{{ Sheet::text($employee->position) }}</p>
            </div>
            @if ($photoUrl)
                <img src="{{ $photoUrl }}" alt="{{ $name }}" class="photo" />
            @else
                <div class="photo blank" aria-label="No photo on file">Photo</div>
            @endif
        </header>

        <div class="identity">
            <dl class="fields">
                {!! $field('Employee ID', Sheet::text($employee->employee_id_no)) !!}
                {!! $field('Area office', $siteName, 2) !!}
                {!! $field('Date hired', Sheet::date($employee->date_hired)) !!}
            </dl>
        </div>

        <div class="block">
            <h2 class="block-title"><span class="block-number">01</span> Personal information</h2>
            <dl class="fields">
                {!! $field('Birthdate', Sheet::date($employee->birth_date)) !!}
                {!! $field('Age', Sheet::age($employee)) !!}
                {!! $field('Civil status', Sheet::civilStatus($employee)) !!}
                {!! $field('Citizenship', Sheet::citizenship($employee)) !!}
                {!! $field('Birthplace', Sheet::text($employee->place_of_birth), 2) !!}
                {!! $field('Religion', Sheet::text($employee->religion), 2) !!}
                {!! $field('Height', Sheet::measure($employee->height_cm, 'cm')) !!}
                {!! $field('Weight', Sheet::measure($employee->weight_kg, 'kg')) !!}
                {!! $field('Blood type', Sheet::text($employee->blood_type), 2) !!}
                {!! $field('Home address', Sheet::homeAddress($employee), 4) !!}
                {!! $field('E-mail address', Sheet::email($employee->email), 2, true) !!}
                {!! $field('Cell no', Sheet::text($employee->phone)) !!}
                {!! $field('Tel. no', Sheet::text($employee->telephone)) !!}
            </dl>
        </div>

        <div class="block">
            <h2 class="block-title"><span class="block-number">02</span> Emergency contact</h2>
            <dl class="fields">
                {!! $field('Name', Sheet::text($employee->emergency_name), 2) !!}
                {!! $field('Relationship', Sheet::text($employee->emergency_relation)) !!}
                {!! $field('Contact number', Sheet::text($employee->emergency_phone)) !!}
                {!! $field('Address', Sheet::text($employee->emergency_address), 4) !!}
            </dl>
        </div>

        <div class="block">
            <h2 class="block-title"><span class="block-number">03</span> Family background</h2>
            <dl class="fields">
                {!! $field("Spouse's name", Sheet::spouseName($employee), 2) !!}
                {!! $field('Employment', Sheet::spouseEmployment($employee)) !!}
                {!! $field('No. of children', Sheet::childCount($employee)) !!}
            </dl>
            <div class="kids">
                {{-- The printout lists names only; birthdates stay in the employee record. --}}
                <p class="kid-row kid-head"><span>Children's name</span></p>
                @foreach (Sheet::children($employee) as [$child])
                    <p class="kid-row"><span class="v">{{ $child }}</span></p>
                @endforeach
            </div>
        </div>

        <div class="block">
            <h2 class="block-title"><span class="block-number">04</span> Educational background</h2>
            <dl class="fields">
                {!! $field('College', Sheet::education($employee, 'COLLEGE')['school'], 2) !!}
                {!! $field('Course', Sheet::education($employee, 'COLLEGE')['course']) !!}
                {!! $field('Year graduated', Sheet::education($employee, 'COLLEGE')['year']) !!}
                {!! $field('Graduate studies', Sheet::education($employee, 'GRADUATE')['school'], 2) !!}
                {!! $field('Course / Masteral', Sheet::education($employee, 'GRADUATE')['course']) !!}
                {!! $field('Year graduated', Sheet::education($employee, 'GRADUATE')['year']) !!}
            </dl>
        </div>

        <div class="block">
            <h2 class="block-title"><span class="block-number">05</span> Statutory &amp; bank details</h2>
            <dl class="fields">
                {!! $field('SSS / GSIS no', Sheet::text($employee->sss_no)) !!}
                {!! $field('PhilHealth no', Sheet::text($employee->philhealth_no)) !!}
                {!! $field('Pag-IBIG no', Sheet::text($employee->pagibig_no)) !!}
                {!! $field('TIN no', Sheet::text($employee->tin)) !!}
                {!! $field('Bank and branch', Sheet::text($employee->bank_name), 2) !!}
                {!! $field('Account no', Sheet::text($employee->bank_account_no), 2) !!}
            </dl>
        </div>

        {{-- License and passport remain on the Documents card, not this sheet. --}}
        <footer class="sheet-footer"><span>Confidential · Personnel record</span><span>201 file · 1 / 1</span></footer>
    </div>
</section>
