{{--
    The Application for Leave, as My Portal's filing form: every field of
    PPMC's printed form in its order, compact, in the system's colours and
    controls.

    ONE FRAME, ONE PATTERN. The form is a single bordered grid, like the
    paper: bands name its parts, every part below a band is a row of the same
    two columns, every cell opens with the same heading, and every choice is
    the same height. `$row`, `$cell`, `$band`, `$heading`, `$caption` and
    `$value` below are that pattern: a new field reuses them rather than
    adding a card or a spacing of its own. The columns fold to one as the
    frame narrows (a container query).

    Sections 1 to 5 come from the employee's own record and are not typed;
    6.A to 6.D are what the employee completes, and FileAbsence checks every
    value again. 6.A lists HR's Leave Settings in the form's order, so a type
    HR has not set up, or that the employee may not use, is not on it; official
    business has its line under "Others". 6.B draws every section, and only
    the picked type's can be answered.

    Section 7 is the office's: nothing here writes it. 7.A states the
    employee's standing today and what this draft would take from it; a leave
    manager records the recommendation and the decision in Leave Management.
    The officers come from Leave Settings (`$officers`) and the head of office
    from the applicant's department or unit (`$applicant['head']`).

    `$filedForm` draws a FILED application read-only instead, on My Portal and
    in Leave Management's review: each choice is a mark
    (`application-form-mark`) and section 7 shows what the office recorded.
    An approved one is a record: everything it is given was stored when it was
    approved (ApplicationFormData::filedForm). Null while filing. `$headingId`
    names the dialog.

    THE FORM IS ALWAYS WHITE: each dialog that draws it carries
    `hris-always-light`, which keeps it out of dark mode.

    `data-leave-details` and `data-commutation` mark only what the picked type
    asks. The address is this installation's own text.
--}}
@use('App\Livewire\Portal\Support\LeaveFormText')
@use('App\Livewire\Portal\Support\PortalLabels')
@use('App\Modules\Timekeeping\Domain\Absence\LeaveApplication')
@use('App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot')
@use('App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits')
@php
    $filing = $filedForm === null;
    $isLeave = $filing && trim($type) !== '' && $type !== PortalLabels::OFFICIAL_BUSINESS;
    $asksApplication = $isLeave && $applicationReady;
    $blank = "\u{2014}";
    $workingDays = ! $filing
        ? ($filedForm['days'] ?? $blank)
        : ($creditHint !== null && $creditHint['error'] === null ? PortalLabels::dayCount($creditHint['needs']) : $blank);
    $filedGroup = null;
    foreach (LeaveApplication::CHOICES as $group => $choices) {
        if (! $filing && isset($choices[(string) ($filedForm['detail'] ?? '')])) {
            $filedGroup = $group;
        }
    }
    $mark = 'livewire.leaves.partials.application-form-mark';
    $officer = static fn (string $slot): array => [($officers[$slot] ?? null)?->name ?: "\u{2014}", ($officers[$slot] ?? null)?->designation ?? ''];

    $row = '-mr-px grid @2xl:grid-cols-[60fr_40fr]';
    $cell = 'min-w-0 space-y-1 border-b border-r border-border px-3 py-2';
    $band = 'flex flex-wrap items-baseline justify-between gap-x-3 border-b border-border bg-muted px-3 py-1.5 text-xs font-bold uppercase tracking-wide';
    $heading = 'text-xs font-semibold uppercase tracking-wide';
    $caption = 'text-[11px] uppercase tracking-wide text-muted-foreground';
    $value = 'min-h-5 break-words text-sm font-medium';
    $sub = 'pt-1 text-xs italic text-muted-foreground';
    $line = 'flex min-h-7 flex-wrap items-center gap-x-3';
    $note = 'text-xs text-muted-foreground';
    $compact = '[&_input]:h-8! [&_input]:text-sm!';
@endphp
<div class="space-y-3" data-leave-application>
    <div class="space-y-0.5 pr-8">
        <flux:heading size="lg" level="2" id="{{ $headingId }}">
            Application for Leave
            @unless ($filing)
                <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ ctype_digit((string) $filedForm['code']) ? '#' : '' }}{{ $filedForm['code'] }}</span>
                <span @class(['ml-2 pill', 'pill-success' => $filedForm['status'] === 'APPROVED', 'pill-danger' => $filedForm['status'] === 'DISAPPROVED', 'pill-warning' => $filedForm['status'] === 'PENDING', 'pill-muted' => ! in_array($filedForm['status'], ['APPROVED', 'DISAPPROVED', 'PENDING'], true)]) data-filed-status>{{ $filedForm['status'] }}</span>
            @endunless
        </flux:heading>
        <flux:text class="text-xs text-muted-foreground">{{ config('copems.company_name') }}</flux:text>
    </div>

    <div class="@container overflow-hidden rounded-lg border border-border">
        <div class="-mb-px">
            {{-- 1 to 5 --}}
            <dl class="-mr-px grid grid-cols-2 @2xl:grid-cols-4" data-leave-applicant>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">1. Office/Department</dt><dd class="{{ $value }}" data-applicant-office>{{ $applicant['office'] !== '' ? $applicant['office'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">2. Last name</dt><dd class="{{ $value }}" data-applicant-last>{{ $applicant['last'] !== '' ? $applicant['last'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">First name</dt><dd class="{{ $value }}" data-applicant-first>{{ $applicant['first'] !== '' ? $applicant['first'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">Middle name</dt><dd class="{{ $value }}" data-applicant-middle>{{ $applicant['middle'] !== '' ? $applicant['middle'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">3. Date of filing</dt><dd class="num {{ $value }}" data-applicant-filed>{{ $applicant['filed'] }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">4. Position</dt><dd class="{{ $value }}" data-applicant-position>{{ $applicant['position'] !== '' ? $applicant['position'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">5. Salary</dt><dd class="num {{ $value }}" data-applicant-salary>{{ $applicant['salary'] !== '' ? $applicant['salary'] : $blank }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $caption }}">Date of receipt</dt><dd class="{{ $value }}" data-applicant-receipt>{{ $applicant['receipt'] !== '' ? $applicant['receipt'] : $blank }}</dd></div>
            </dl>

            <div class="{{ $band }}">6. Details of application</div>

            {{-- 6.A and 6.B --}}
            <div class="{{ $row }}">
                <fieldset class="{{ $cell }}" data-leave-types>
                    <legend class="{{ $heading }} float-left w-full">6.A Type of leave to be availed of</legend>
                    <div class="clear-both">
                        @if ($applyTypes === [])
                            <p class="{{ $note }}">No leave types are set up in Leave Settings yet.</p>
                        @endif
                        @unless ($filing)
                            @foreach ($applyTypes as $option)
                                <div class="{{ $line }}">@include($mark, ['on' => $filedForm['type'] === $option['value'], 'label' => $option['label'], 'citation' => LeaveFormText::citation($option['label'])])</div>
                            @endforeach
                            <p class="{{ $sub }}">Others:</p>
                            <div class="{{ $line }}">@include($mark, ['on' => $filedForm['type'] === PortalLabels::OFFICIAL_BUSINESS, 'label' => 'Official Business', 'citation' => null])</div>
                        @else
                        <flux:radio.group wire:model.live="type" aria-label="Type of leave">
                            @foreach ($applyTypes as $option)
                                @php $citation = LeaveFormText::citation($option['label']); @endphp
                                <div class="{{ $line }}">
                                    <flux:field variant="inline">
                                        <flux:radio value="{{ $option['value'] }}" />
                                        <flux:label><span>{{ $option['label'] }}@if ($citation !== null) <span class="ml-1 text-[11px] font-normal text-muted-foreground">({{ $citation }})</span>@endif</span></flux:label>
                                    </flux:field>
                                </div>
                            @endforeach
                            <p class="{{ $sub }}">Others:</p>
                            <div class="{{ $line }}"><flux:radio value="{{ PortalLabels::OFFICIAL_BUSINESS }}" label="Official Business" /></div>
                        </flux:radio.group>
                        @endunless
                    </div>
                </fieldset>

                <div class="{{ $cell }}">
                    <p class="{{ $heading }}">6.B Details of leave</p>
                    @foreach (LeaveApplication::CHOICES as $group => $choices)
                        @php
                            $active = $asksApplication && $detailSection === $group;
                            $single = count($choices) === 1;
                            $sectionName = 'In case of '.LeaveApplication::SECTION_LABELS[$group];
                        @endphp
                        <div @class(['opacity-50' => ! $active && $filedGroup !== $group]) wire:key="leave-details-{{ $group }}-{{ $active ? 'on' : 'off' }}" @if ($active) data-leave-details="{{ $group }}" @endif>
                            <p class="{{ $sub }}">{{ $sectionName }}:</p>
                            @unless ($filing)
                                @foreach ($choices as $choice => $label)
                                    @php $chosen = ($filedForm['detail'] ?? null) === $choice; @endphp
                                    <div class="{{ $line }}">
                                        @include($mark, ['on' => $chosen, 'label' => $label, 'citation' => null])
                                        @if ($chosen && trim((string) ($filedForm['note'] ?? '')) !== '')<span class="{{ $value }}" data-filed-note>{{ $filedForm['note'] }}</span>@endif
                                    </div>
                                @endforeach
                            @elseif ($single)
                                @php $specify = LeaveApplication::SPECIFY[array_key_first($choices)]; @endphp
                                <div class="{{ $line }}">
                                    @if ($active)
                                        <flux:input size="sm" wire:model="leaveDetailNote" placeholder="{{ $specify }}" aria-label="{{ $specify }}" maxlength="{{ LeaveApplication::NOTE_MAX }}" class="min-w-0 flex-1" data-leave-detail-note />
                                    @else
                                        <span class="text-sm">{{ $specify }}</span>
                                    @endif
                                </div>
                            @elseif ($active)
                                <flux:radio.group wire:model.live="leaveDetail" :aria-label="$sectionName">
                                    @foreach ($choices as $choice => $label)
                                        @php $specify = LeaveApplication::SPECIFY[$choice] ?? LeaveApplication::SPECIFY_OPTIONAL[$choice] ?? null; @endphp
                                        <div class="{{ $line }}" wire:key="leave-detail-{{ $choice }}">
                                            <flux:radio value="{{ $choice }}" :label="$label" />
                                            @if ($specify !== null && $leaveDetail === $choice)
                                                <flux:input size="sm" wire:model="leaveDetailNote" placeholder="{{ $specify }}" aria-label="{{ $specify }}" maxlength="{{ LeaveApplication::NOTE_MAX }}" class="min-w-0 flex-1" data-leave-detail-note />
                                            @elseif ($specify !== null)
                                                <span class="{{ $note }}">({{ $specify }})</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </flux:radio.group>
                            @else
                                <flux:radio.group :aria-label="$sectionName">
                                    @foreach ($choices as $choice => $label)
                                        @php $specify = LeaveApplication::SPECIFY[$choice] ?? LeaveApplication::SPECIFY_OPTIONAL[$choice] ?? null; @endphp
                                        <div class="{{ $line }}">
                                            <flux:radio value="{{ $choice }}" :label="$label" disabled />
                                            @if ($specify !== null)<span class="{{ $note }}">({{ $specify }})</span>@endif
                                        </div>
                                    @endforeach
                                </flux:radio.group>
                            @endunless
                        </div>
                    @endforeach
                    <p class="{{ $sub }}">Other purpose:</p>
                    <div class="{{ $line }}">
                        <span class="text-sm font-medium">Monetization of Leave Credits</span>
                        @if (! $filing)
                            <span class="{{ $note }}">Filed separately as a cash conversion.</span>
                        @elseif ($cashOutTypes !== [])
                            <flux:button type="button" size="xs" variant="outline" wire:click="convertCredits" data-apply-convert>Convert credits to cash</flux:button>
                        @else
                            <span class="{{ $note }}">None of your credits can be converted.</span>
                        @endif
                    </div>
                    <div class="{{ $line }}">
                        <span class="text-sm font-medium">Terminal Leave</span>
                        <span class="{{ $note }}">HR files this when you leave the service.</span>
                    </div>
                </div>
            </div>

            {{-- 6.C and 6.D --}}
            <div class="{{ $row }}">
                <div class="{{ $cell }} space-y-2!">
                    <dl class="flex items-baseline justify-between gap-3">
                        <dt class="{{ $heading }}">6.C Number of working days applied for</dt>
                        <dd class="num text-sm font-semibold" data-working-days>{{ $workingDays }}</dd>
                    </dl>
                    @unless ($filing)
                        <dl class="grid grid-cols-2 gap-2 @4xl:grid-cols-3">
                            <div><dt class="{{ $caption }}">Inclusive dates · From</dt><dd class="num {{ $value }}" data-filed-start>{{ $filedForm['start'] }}</dd></div>
                            <div><dt class="{{ $caption }}">To</dt><dd class="num {{ $value }}" data-filed-end>{{ $filedForm['end'] }}</dd></div>
                            <div><dt class="{{ $caption }}">Day type</dt><dd class="{{ $value }}">{{ $filedForm['day_type'] }}</dd></div>
                        </dl>
                        <dl><dt class="{{ $caption }}">Reason (optional)</dt><dd class="{{ $value }}" data-filed-reason>{{ trim((string) $filedForm['reason']) !== '' ? $filedForm['reason'] : $blank }}</dd></dl>
                    @else
                    <div class="grid grid-cols-2 gap-2 @4xl:grid-cols-3">
                        <div class="space-y-0.5">
                            <p class="{{ $caption }}">Inclusive dates · From</p>
                            <x-kit.date-field wire:model.live="start" aria-label="From date" class="{{ $compact }}" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="{{ $caption }}">To</p>
                            <x-kit.date-field wire:model.live="end" aria-label="To date" class="{{ $compact }}" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="{{ $caption }}">Day type</p>
                            <x-kit.select size="sm" id="portal-day-type" aria-label="Day type" wire:model.live="dayType">
                                @foreach (PortalLabels::DAY_TYPES as $option => $label)
                                    <flux:select.option value="{{ $option }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>
                        @if ($dayType === 'HALF_DAY' && $halvesReady)
                            <div class="space-y-0.5">
                                <p class="{{ $caption }}">Which half</p>
                                <x-kit.select size="sm" id="portal-half" aria-label="Which half" wire:model.live="half">
                                    <flux:select.option value="">Either half</flux:select.option>
                                    @foreach (PortalLabels::HALVES as $option => $label)
                                        <flux:select.option value="{{ $option }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                        @endif
                    </div>
                    <div class="space-y-0.5">
                        <p class="{{ $caption }}">Reason (optional)</p>
                        <flux:textarea rows="2" maxlength="2000" wire:model="reason" aria-label="Reason (optional)" class="text-sm!" />
                    </div>
                    @endunless
                </div>

                <div class="{{ $cell }} space-y-2!">
                    <fieldset @if ($asksApplication) data-commutation @endif wire:key="leave-commutation-{{ $asksApplication ? 'on' : 'off' }}">
                        <legend class="{{ $heading }} float-left w-full">6.D Commutation</legend>
                        <div class="clear-both">
                            @if (! $filing)
                                @foreach (LeaveApplication::COMMUTATION_LABELS as $option => $label)
                                    <div class="{{ $line }}">@include($mark, ['on' => ($filedForm['commutation'] ?? null) === $option, 'label' => $label, 'citation' => null])</div>
                                @endforeach
                            @elseif ($asksApplication)
                                <flux:radio.group wire:model="commutation" aria-label="Commutation">
                                    @foreach (LeaveApplication::COMMUTATION_LABELS as $option => $label)
                                        <div class="{{ $line }}"><flux:radio value="{{ $option }}" :label="$label" /></div>
                                    @endforeach
                                </flux:radio.group>
                            @else
                                <flux:radio.group aria-label="Commutation" class="opacity-50">
                                    @foreach (LeaveApplication::COMMUTATION_LABELS as $option => $label)
                                        <div class="{{ $line }}"><flux:radio value="{{ $option }}" :label="$label" disabled /></div>
                                    @endforeach
                                </flux:radio.group>
                            @endif
                        </div>
                    </fieldset>
                    <dl>
                        <dt class="{{ $caption }}">Signature of applicant</dt>
                        <dd class="{{ $value }}">Filed online by {{ trim($applicant['first'].' '.$applicant['last']) }}</dd>
                    </dl>
                </div>
            </div>

            {{-- What this draft would cost: said beside the choices that decide it. --}}
            @if ($filing && ($dateError !== null || $creditHint !== null))
                <div class="space-y-1 border-b border-border px-3 py-2" data-leave-consequences>
                    @if ($dateError !== null)
                        <p class="text-sm text-danger">{{ $dateError }}</p>
                    @endif

                    {{-- The selected type's credits in the request's start
                         year: a short balance never stops the request — the
                         days it does not cover would be without pay, and the
                         notice says so. Only the leave rule's refusal (a type
                         not acquired yet) disables Submit; FileAbsence checks
                         again on save. --}}
                    @if ($creditHint !== null)
                        @php
                            $hintBalance = $creditHint['balance'];
                            $hintNeeds = PortalLabels::dayCount($creditHint['needs']);
                        @endphp
                        @if ($creditHint['year'] !== $creditYear)
                            <p class="text-sm text-muted-foreground" data-credit-year>
                                This request falls in {{ $creditHint['year'] }}; the credits shown are for {{ $creditYear }}.
                            </p>
                        @endif
                        @if ($creditHint['error'] === null && $creditHint['notice'] !== null)
                            <p class="text-sm text-foreground" role="status" data-credit-notice>
                                <span class="pill pill-caution">Partly unpaid</span>
                                {{ $creditHint['notice'] }}
                            </p>
                        @endif
                        {{-- The leave type may go below zero (HR's limit): say how far approving would take it. --}}
                        @if ($creditHint['error'] === null && $creditHint['below_zero'] !== null)
                            <p class="text-sm text-foreground" role="status" data-credit-below-zero>
                                <span class="pill pill-caution">Negative balance</span>
                                {{ $creditHint['below_zero'] }}
                            </p>
                        @endif
                        @if ($creditHint['error'] !== null)
                            <p class="text-sm text-danger" role="alert" data-credit-error>{{ $creditHint['error'] }}</p>
                        @elseif ($hintBalance !== null)
                            <p class="text-sm text-muted-foreground" data-credit-hint>
                                This request needs
                                <span @class(['num', 'pill pill-caution' => $creditHint['needs'] > $hintBalance->available])>{{ $hintNeeds }} {{ $creditHint['needs'] === 1.0 ? 'day' : 'days' }}</span>
                                (rest days and holidays cost nothing);
                                you have <span class="num">{{ PortalLabels::credits((float) $hintBalance->available) }}</span>
                                {{ $hintBalance->label }} {{ $hintBalance->available === 1.0 ? 'credit' : 'credits' }} available{{ $hintBalance->spoken_for > 0 ? ', with '.PortalLabels::credits($hintBalance->spoken_for).' set aside for cash conversion' : '' }}.
                            </p>
                        @endif
                        {{-- A leave type whose rule falls back on another: what the request lacks is drawn from that type when it is approved. --}}
                        @if ($creditHint['error'] === null && $creditHint['fallback'] !== null)
                            <p class="text-sm text-muted-foreground" data-credit-fallback>
                                If these run out, the rest is drawn from your
                                <span class="num">{{ PortalLabels::credits($creditHint['fallback']['available']) }}</span>
                                {{ $creditHint['fallback']['name'] }} {{ $creditHint['fallback']['available'] === 1.0 ? 'credit' : 'credits' }} when the request is approved.
                            </p>
                        @endif
                    @endif
                </div>
            @endif

            <div class="{{ $band }}">
                <span>7. Details of action on application</span>
                <span class="text-[11px] font-normal normal-case tracking-normal text-muted-foreground">{{ $filing ? 'Completed by the office after you submit.' : 'Completed by the office.' }}</span>
            </div>

            {{-- 7.A and 7.B --}}
            <div class="{{ $row }}">
                <div class="{{ $cell }} space-y-2!">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="{{ $heading }}">7.A Certification of leave credits</p>
                        <p class="{{ $note }}">As of <span class="num">{{ $applicant['filed'] }}</span></p>
                    </div>
                    @if ($certification === [])
                        <p class="{{ $note }}">No leave credits have been entered yet.</p>
                    @else
                        <div class="overflow-hidden rounded-md border border-border">
                            <div class="hris-table-wrap">
                                <table class="hris-table text-xs! [&_td]:py-1! [&_th]:py-1!" data-leave-certification>
                                    <thead>
                                        <tr>
                                            <th aria-label="Line"></th>
                                            @foreach ($certification as $column)
                                                <th><span class="block text-right">{{ $column['name'] }} ({{ $column['unit'] === LeaveTypeCredits::UNIT_HOURS ? 'hours' : 'days' }})</span></th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (['earned' => 'Total earned', 'less' => 'Less this application', 'balance' => 'Balance'] as $entry => $entryLabel)
                                            <tr wire:key="leave-certification-{{ $entry }}">
                                                <td class="whitespace-nowrap">{{ $entryLabel }}</td>
                                                @foreach ($certification as $column)
                                                    <td class="num text-right">{{ PortalLabels::credits($column[$entry]) }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    <dl>
                        <dt class="{{ $caption }}">Certified by</dt>
                        <dd class="{{ $value }}" data-officer-certified>{{ $officer(LeaveSignatorySlot::CERTIFIED)[0] }}</dd>
                        <dd class="{{ $note }}">{{ $officer(LeaveSignatorySlot::CERTIFIED)[1] }}</dd>
                    </dl>
                </div>

                <div class="{{ $cell }} space-y-2!">
                    <p class="{{ $heading }}">7.B Recommendation</p>
                    @if ($filing)
                        <flux:radio.group aria-label="Recommendation" class="opacity-50">
                            <div class="{{ $line }}"><flux:radio value="FOR_APPROVAL" label="For approval" disabled /></div>
                            <div class="{{ $line }}"><flux:radio value="FOR_DISAPPROVAL" label="For disapproval due to" disabled /></div>
                        </flux:radio.group>
                    @else
                        <div class="{{ $line }}">@include($mark, ['on' => ($filedForm['recommendation'] ?? null) === 'FOR_APPROVAL', 'label' => 'For approval', 'citation' => null])</div>
                        <div class="{{ $line }}">
                            @include($mark, ['on' => ($filedForm['recommendation'] ?? null) === 'FOR_DISAPPROVAL', 'label' => 'For disapproval due to', 'citation' => null])
                            @if (($filedForm['recommendation'] ?? null) === 'FOR_DISAPPROVAL')<span class="{{ $value }}" data-filed-recommendation-reason>{{ $filedForm['recommendation_reason'] }}</span>@endif
                        </div>
                    @endif
                    <dl>
                        <dt class="{{ $caption }}">Authorized official/Head of office</dt>
                        <dd class="{{ $value }}" data-office-head>{{ $applicant['head']['name'] ?? $blank }}</dd>
                        @if (($applicant['head']['designation'] ?? '') !== '')<dd class="{{ $note }}">{{ $applicant['head']['designation'] }}</dd>@endif
                    </dl>
                </div>
            </div>

            {{-- 7.C and 7.D --}}
            <div class="{{ $row }}">
                <div class="{{ $cell }}">
                    <p class="{{ $heading }}">7.C Approved for</p>
                    <dl class="grid grid-cols-3 gap-3">
                        <div><dt class="{{ $caption }}">Days with pay</dt><dd class="num {{ $value }}" data-filed-with-pay>{{ $filedForm['with_pay'] ?? $blank }}</dd></div>
                        <div><dt class="{{ $caption }}">Days without pay</dt><dd class="num {{ $value }}" data-filed-without-pay>{{ $filedForm['without_pay'] ?? $blank }}</dd></div>
                        <div><dt class="{{ $caption }}">Others (specify)</dt><dd class="{{ $value }}" data-filed-others>{{ trim((string) ($filedForm['others'] ?? '')) !== '' ? $filedForm['others'] : $blank }}</dd></div>
                    </dl>
                </div>
                <div class="{{ $cell }}">
                    <p class="{{ $heading }}">7.D Disapproved due to</p>
                    <p class="{{ $value }}" data-filed-disapproved>{{ trim((string) ($filedForm['disapproved'] ?? '')) !== '' ? $filedForm['disapproved'] : $blank }}</p>
                </div>
            </div>

            <dl class="-mr-px border-b border-r border-border px-3 py-2">
                <dt class="{{ $caption }}">Approved by</dt>
                <dd class="{{ $value }}" data-officer-approved>{{ $officer(LeaveSignatorySlot::APPROVED)[0] }}</dd>
                <dd class="{{ $note }}">{{ $officer(LeaveSignatorySlot::APPROVED)[1] }}</dd>
            </dl>
        </div>
    </div>
</div>
