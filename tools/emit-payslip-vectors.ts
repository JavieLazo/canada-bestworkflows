/**
 * Emits golden PAYSLIP vectors from the REAL TypeScript payroll engine.
 *
 * Run:  bun tools/emit-payslip-vectors.ts
 *
 * This imports `computePayslip` from ../../copems/src/lib/payroll.ts directly —
 * never a transcription — so the fixture cannot drift from the engine it pins
 * down. Each NDJSON line is one vector:
 *
 *     { "input": <the full computePayslip args object>, "expected": <PayslipDraft> }
 *
 * `computePayslip` internally calls `computeDtr`, so a vector at this boundary
 * verifies the whole chain: punch pairing, the overnight carry-over slice, day
 * buckets, holiday pricing, OT/NSD/rest-day, lateness/undertime, contributions
 * (allow/fixed/mode/endsMonth) and withholding.
 *
 * Generation is ADVERSARIAL, not a tidy sweep — a tidy sweep leaves the traps
 * unexercised, and the statutory harness scores ZERO detections without its
 * semi-monthly halving step. Hence:
 *
 *   Tier A  monthly-rate traps under a canonical full-attendance period:
 *           every whole peso 10,000..11,000, the canonical 10,241, 0.01-step
 *           sweeps across an SSS MSC edge and a BIR bracket edge, an
 *           annualized-tax sweep, a MONTHLY-mode month-end sweep, and
 *           degenerate rates (0 / negative / null / string / 1e300 / 0.005).
 *
 *   Tier B  seeded deterministic fuzz (mulberry32, constant seed — never
 *           Math.random): shifts fixed/FLEXIBLE/night-crossing/with-break,
 *           0-40 punches (pairs, unpaired, duplicate-millisecond,
 *           cross-midnight carry), leaves paid/unpaid/half-day overlapping the
 *           period edges, holidays REGULAR/SPECIAL on rest days worked and
 *           unworked, formulas default/zero-cap/custom/junk, bonus,
 *           allowancesOverride/earningsOverride set and unset, contribution
 *           modes SEMI_MONTHLY vs MONTHLY vs the empty-string quirk, periods
 *           that do and do not end the month, fixed contribution overrides,
 *           allow flags off, hire/resign dates inside the period, and
 *           hired > resigned (the empty-days path).
 */
import { computePayslip } from "../../copems/src/lib/payroll";
import type {
  EmployeeProfile,
  PayrollEarningOverride,
  PayrollSettings,
} from "../../copems/src/lib/payroll";
import type {
  AttendanceLog,
  HolidaySetting,
  LeaveRequest,
  Shift,
} from "../../copems/src/lib/dtr";

// ─── Deterministic PRNG — mulberry32, constant seed ──────────────────────────
const SEED = 0x5eed_c0de;
let state = SEED >>> 0;
function rnd(): number {
  state |= 0;
  state = (state + 0x6d2b79f5) | 0;
  let t = Math.imul(state ^ (state >>> 15), 1 | state);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const chance = (p: number) => rnd() < p;
const int = (lo: number, hi: number) => lo + Math.floor(rnd() * (hi - lo + 1));
const pick = <T>(a: readonly T[]): T => a[Math.floor(rnd() * a.length)]!;

// ─── Employee factory ────────────────────────────────────────────────────────
// Structural on both sides: the reference's EmployeeProfile is a structural
// type and the PHP port reads `object $employee` with `?? null` fallbacks, so
// only the fields computePayslip actually consumes are carried — the fixture
// would otherwise blow past its size budget on 40 dead columns per vector.
function employee(overrides: Partial<Record<keyof EmployeeProfile, unknown>>): EmployeeProfile {
  return {
    id: "E1",
    date_hired: "2020-01-01",
    date_resigned: null,
    monthly_rate: 25000,
    hourly_rate: 0,
    allow_sss: true,
    allow_philhealth: true,
    allow_pagibig: true,
    allow_withholding_tax: true,
    annualize_tax: false,
    fixed_tax: 0,
    percent_tax: 0,
    fixed_sss_ee: 0,
    fixed_sss_er: 0,
    fixed_philhealth_ee: 0,
    fixed_philhealth_er: 0,
    fixed_pagibig_ee: 0,
    fixed_pagibig_er: 0,
    sss_mode: null,
    philhealth_mode: null,
    pagibig_mode: null,
    ...(overrides as object),
  } as EmployeeProfile;
}

// ─── Calendar helpers (all UTC arithmetic, deterministic) ────────────────────
function addDays(day: string, n: number): string {
  const d = new Date(`${day}T00:00:00.000Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}
function eachDay(start: string, end: string): string[] {
  const out: string[] = [];
  for (let d = start; d <= end; d = addDays(d, 1)) out.push(d);
  return out;
}
function dow(day: string): number {
  return new Date(`${day}T00:00:00.000Z`).getUTCDay();
}
function lastDayOfMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** Epoch ms of a Manila wall-clock minute-of-day on a calendar day. */
function manilaMs(day: string, minutes: number, ms = 0): number {
  return Date.parse(`${day}T00:00:00.000+08:00`) + minutes * 60_000 + ms;
}
/** Serialize an instant the two ways the reference accepts. */
function iso(msEpoch: number, offsetless: boolean): string {
  const z = new Date(msEpoch).toISOString();
  if (!offsetless) return z;
  // Manila wall clock without a zone — read as +08:00 by both engines.
  return new Date(msEpoch + 8 * 3_600_000).toISOString().replace("Z", "");
}

// Only log_type and logged_at reach the DTR arithmetic in either engine
// (dtr.ts reads nothing else; AttendanceLog::fromArray defaults the rest), so
// only those ride the fixture. The `as` cast is what keeps this structural.
function log(type: "TIME_IN" | "TIME_OUT", msEpoch: number, offsetless = false): AttendanceLog {
  return {
    log_type: type,
    logged_at: iso(msEpoch, offsetless),
  } as AttendanceLog;
}

// ─── Vector accumulation ─────────────────────────────────────────────────────
type Args = Parameters<typeof computePayslip>[0];
const lines: string[] = [];
const stats: Record<string, number> = {};
const bump = (k: string) => (stats[k] = (stats[k] ?? 0) + 1);

function emit(input: Args): void {
  const expected = computePayslip(input);
  if (expected.net_pay < 0) bump("negative_net");
  if (expected.holiday_days > 0) bump("worked_holiday");
  if (expected.rest_days > 0) bump("worked_rest_day");
  if (expected.overtime_pay > 0) bump("overtime");
  if (expected.night_differential > 0) bump("nsd");
  if (expected.late_minutes > 0) bump("late");
  if (expected.undertime_minutes > 0) bump("undertime");
  if (expected.unpaid_leave_days > 0) bump("unpaid_leave");
  if (expected.work_days % 1 !== 0) bump("fractional_work_days");
  lines.push(JSON.stringify({ input, expected }));
}

// ═════════════════════════════════════════════════════════════════════════════
// Tier A — monthly-rate traps under a canonical full-attendance March 2026.
// ═════════════════════════════════════════════════════════════════════════════
const CANON_SHIFT: Omit<Shift, "employee_id"> = {
  mode: "FIXED",
  required_hours: null,
  work_days: [1, 2, 3, 4, 5],
  start_time: "08:00:00",
  end_time: "17:00:00",
  grace_minutes: 15,
  break_minutes: 60,
};

function canonicalLogs(periodStart: string, periodEnd: string): AttendanceLog[] {
  const out: AttendanceLog[] = [];
  for (const day of eachDay(periodStart, periodEnd)) {
    const wd = dow(day);
    if (wd === 0 || wd === 6) continue;
    out.push(log("TIME_IN", manilaMs(day, 8 * 60)));
    out.push(log("TIME_OUT", manilaMs(day, 17 * 60)));
  }
  return out;
}

const FIRST_CUT = { start: "2026-03-01", end: "2026-03-15" };
const SECOND_CUT = { start: "2026-03-16", end: "2026-03-31" };
const LOGS_FIRST = canonicalLogs(FIRST_CUT.start, FIRST_CUT.end);
const LOGS_SECOND = canonicalLogs(SECOND_CUT.start, SECOND_CUT.end);

function tierA(monthly: unknown, extra: Partial<Record<keyof EmployeeProfile, unknown>> = {}, cut = FIRST_CUT, logs = LOGS_FIRST): void {
  emit({
    employee: employee({ monthly_rate: monthly, ...extra }),
    logs,
    shift: CANON_SHIFT,
    leaves: [],
    periodStart: cut.start,
    periodEnd: cut.end,
    bonus: 0,
  });
}

// A1 — every whole peso across a working decade slice, plus the canonical trap.
for (let m = 10_000; m <= 11_000; m++) tierA(m);
tierA(10_241);

// A2 — 0.01-step sweep across one SSS MSC rounding edge (MSC step at 10,250).
for (let c = -50; c <= 50; c++) tierA(10_250 + c / 100);

// A3 — 0.01-step sweep across a BIR semi-monthly bracket edge. gross ≈
// monthly × 11/22 here, so monthly ≈ 37,45x drives taxable across ₱16,667
// once contributions and the halving have been applied.
for (let c = 0; c <= 2_000; c++) tierA(37_450 + c / 100);

// A4 — the same bracket-edge region through the ANNUALIZED withholding path.
for (let c = 0; c <= 200; c++) tierA(37_450 + c / 10, { annualize_tax: true });

// A5 — MONTHLY contribution modes across the month-end cut-off (charged whole)
// and the first cut-off (charged nothing), plus the empty-string mode quirk.
for (let m = 10_000; m <= 11_000; m += 10) {
  tierA(m, { sss_mode: "MONTHLY", philhealth_mode: "MONTHLY", pagibig_mode: "" }, SECOND_CUT, LOGS_SECOND);
  tierA(m, { sss_mode: "MONTHLY", philhealth_mode: "QUARTERLY", pagibig_mode: "MONTHLY" });
}

// A6 — degenerate monthly rates. NaN itself cannot ride JSON; its reachable
// on-ramps can: null (Number(null) === 0) and a non-numeric string
// (Number("x") is NaN, which every rounded output collapses to 0).
for (const m of [0, 0.005, 0.01, -1, -12_345.67, 1e300, 1_499.99, 4_999.99, 34_999.99, 100_000.01, null, "10241", "not-a-number"]) {
  tierA(m as unknown);
}

// A7 — fixed contribution overrides with odd centavos: the halving of an
// odd-centavo DECIMAL(12,2) override manufactures the exact half-centavo.
for (let c = 0; c <= 200; c++) {
  tierA(25_000, {
    fixed_sss_ee: 250.01 + c,
    fixed_philhealth_ee: 128.01 + c,
    fixed_pagibig_ee: 100.05 + c / 100,
    fixed_sss_er: 500.03,
    fixed_philhealth_er: 128.01,
    fixed_pagibig_er: 100.05,
  });
}

const tierACount = lines.length;

// ═════════════════════════════════════════════════════════════════════════════
// Tier B — seeded fuzz across the whole argument surface.
// ═════════════════════════════════════════════════════════════════════════════
const FUZZ_VECTORS = 3_200;

const FIXED_SPANS: readonly [string, string][] = [
  ["08:00:00", "17:00:00"],
  ["09:00:00", "18:00:00"],
  ["06:00:00", "14:00:00"],
  ["22:00:00", "06:00:00"], // night, crosses midnight
  ["20:00:00", "05:00:00"], // night, crosses midnight
  ["23:00:00", "08:00:00"], // night, crosses midnight
  ["13:00:00", "22:30:00"],
  ["08:00:00", "08:00:00"], // zero-length day → workingHours guard
];

function fuzzShift(): Omit<Shift, "employee_id"> | null {
  if (chance(0.08)) return null;
  const flexible = chance(0.25);
  const workDays = pick<readonly (number[] | null)[]>([
    [1, 2, 3, 4, 5],
    [0, 1, 2, 3, 4, 5, 6],
    [1, 2, 3, 4, 5, 6],
    [2, 4],
    [],
    null,
  ]);
  const withBreakWindow = chance(0.35);
  const span = pick(FIXED_SPANS);
  return {
    mode: flexible ? "FLEXIBLE" : "FIXED",
    required_hours: flexible ? pick([null, 0, 4, 7.51, 8, 8.5, 12]) : null,
    work_days: workDays === null ? null : [...workDays],
    start_time: span[0],
    end_time: span[1],
    grace_minutes: pick([0, 5, 15, 30, 60]),
    break_minutes: pick([0, 30, 60, 90, 600]),
    ...(withBreakWindow
      ? {
          break_start: "12:00:00",
          break_end: "13:00:00",
          requires_break_punch: pick([true, false]),
        }
      : {}),
  };
}

function shiftStartMinutes(shift: Omit<Shift, "employee_id"> | null): number {
  if (!shift) return 8 * 60;
  const [h, m] = shift.start_time.split(":");
  return Number(h) * 60 + Number(m);
}
function shiftSpanMinutes(shift: Omit<Shift, "employee_id"> | null): number {
  if (!shift) return 9 * 60;
  const [sh, sm] = shift.start_time.split(":");
  const [eh, em] = shift.end_time.split(":");
  const s = Number(sh) * 60 + Number(sm);
  const e = Number(eh) * 60 + Number(em);
  return e <= s ? e + 24 * 60 - s : e - s;
}

function fuzzLogs(shift: Omit<Shift, "employee_id"> | null, periodStart: string, periodEnd: string): AttendanceLog[] {
  if (chance(0.1)) return [];
  const out: AttendanceLog[] = [];
  const startMin = shiftStartMinutes(shift);
  const span = Math.max(shiftSpanMinutes(shift), 60);
  // Production feeds attendanceDateRange: one adjacent day either side.
  for (const day of eachDay(addDays(periodStart, -1), addDays(periodEnd, 1))) {
    if (out.length >= 38) break;
    if (!chance(0.62)) continue;
    const offsetless = chance(0.2);
    const jitterIn = int(-40, 90); // early / on-time / late
    const jitterOut = int(-120, 6 * 60); // undertime through heavy overtime
    const inMs = manilaMs(day, startMin + jitterIn, pick([0, 1, 499, 500, 999]));
    const outMs = manilaMs(day, startMin + span + jitterOut, pick([0, 1, 499, 500, 999]));
    const kind = rnd();
    if (kind < 0.72) {
      out.push(log("TIME_IN", inMs, offsetless));
      out.push(log("TIME_OUT", Math.max(outMs, inMs + 60_000), offsetless));
      if (chance(0.12)) {
        // punched break in the middle of the day
        out.push(log("TIME_OUT", manilaMs(day, startMin + Math.floor(span / 2))));
        out.push(log("TIME_IN", manilaMs(day, startMin + Math.floor(span / 2) + 60)));
      }
    } else if (kind < 0.82) {
      out.push(log("TIME_IN", inMs, offsetless)); // unpaired IN → INCOMPLETE
    } else if (kind < 0.9) {
      out.push(log("TIME_OUT", outMs, offsetless)); // orphan OUT
    } else {
      // duplicate-millisecond double punch
      out.push(log("TIME_IN", inMs, offsetless));
      out.push(log("TIME_IN", inMs, offsetless));
      out.push(log("TIME_OUT", Math.max(outMs, inMs + 60_000), offsetless));
    }
  }
  return out.slice(0, 40);
}

function fuzzLeaves(periodStart: string, periodEnd: string): LeaveRequest[] {
  const out: LeaveRequest[] = [];
  const n = pick([0, 0, 0, 1, 1, 2, 3]);
  for (let i = 0; i < n; i++) {
    const anchor = pick([
      addDays(periodStart, int(-2, 2)), // overlaps the start edge
      addDays(periodEnd, int(-2, 2)), // overlaps the end edge
      addDays(periodStart, int(0, 12)),
    ]);
    const end = addDays(anchor, int(0, 4));
    out.push({
      id: `LV${i + 1}`,
      employee_id: "E1",
      request_type: pick(["SICK", "VACATION", "EMERGENCY", "BUSINESS_TRAVEL"]),
      start_date: anchor,
      end_date: end,
      status: pick(["APPROVED", "APPROVED", "APPROVED", "PENDING", "REJECTED"]),
      is_paid: pick([true, true, false, null]),
      day_type: pick(["WHOLE_DAY", "HALF_DAY", null]),
    });
  }
  return out;
}

function fuzzHolidays(periodStart: string, periodEnd: string): HolidaySetting[] {
  const out: HolidaySetting[] = [];
  const n = pick([0, 0, 1, 1, 2, 3]);
  const days = eachDay(periodStart, periodEnd);
  for (let i = 0; i < n; i++) {
    const day = pick(days);
    const annual = chance(0.4);
    out.push({
      name: `H${i + 1}`,
      date: annual ? `201${int(0, 9)}-${day.slice(5)}` : day,
      schedule: annual ? "ANNUAL" : "ONE_TIME",
      type: pick(["REGULAR", "SPECIAL"]),
      percent: pick([0, 30, 100, 130, 200, 250]),
    });
  }
  return out;
}

function fuzzSettings(periodStart: string, periodEnd: string): PayrollSettings | undefined {
  const holidays = fuzzHolidays(periodStart, periodEnd);
  if (chance(0.2) && holidays.length === 0) return undefined;
  const formulas = pick<PayrollSettings["formulas"]>([
    undefined,
    [],
    [
      { key: "OT", params: [{ key: "percentage", value: 1.25 }, { key: "max_ot", value: 8 }] },
      { key: "NSD", params: [{ key: "percentage", value: 0.1 }, { key: "max_nsd", value: 8 }] },
      { key: "REST_DAY", params: [{ key: "percentage", value: 1.3 }, { key: "max_hours", value: 8 }] },
      { key: "SIL", params: [{ key: "days_per_month", value: 24 }] },
    ],
    // zero caps — legitimate zeroes must not fall back
    [
      { key: "OT", params: [{ key: "percentage", value: 0 }, { key: "max_ot", value: 0 }] },
      { key: "NSD", params: [{ key: "percentage", value: 0 }, { key: "max_nsd", value: 0 }] },
      { key: "REST_DAY", params: [{ key: "percentage", value: 0 }, { key: "max_hours", value: 0 }] },
    ],
    // custom rates with awkward decimals
    [
      { key: "OT", params: [{ key: "percentage", value: 1.375 }, { key: "max_ot", value: 3.5 }] },
      { key: "NSD", params: [{ key: "percentage", value: 0.21 }, { key: "max_nsd", value: 5.25 }] },
      { key: "REST_DAY", params: [{ key: "percentage", value: 1.69 }, { key: "max_hours", value: 6 }] },
    ],
    // junk values — both engines must fall back (or coerce) identically.
    //
    // `value: null` is deliberately ABSENT. It is unreachable through the
    // reference's only write path (zod: value: z.number().finite().min(0)),
    // and on it the two engines genuinely disagree: JS Number(null) === 0 (an
    // explicit zero rate) while PayrollRates::valuesOf maps a non-is_numeric
    // value to NAN, which falls back to the default. A hand-edited formulas
    // row carrying null would pay NSD differently, but it is not expressible
    // through the public API, so it is not a vector.
    [
      { key: "OT", params: [{ key: "percentage", value: "wild" as unknown as number }, { key: "max_ot", value: -3 }] },
      { key: "NSD", params: [{ key: "percentage", value: "1e-1" as unknown as number }] },
      { key: "SIL", params: [{ key: "days_per_month", value: 0 }] },
    ],
  ]);
  return { ...(formulas ? { formulas } : {}), holidays };
}

const MONTHLIES = [
  10_241, 25_000, 10_000.55, 33_333.33, 4_999.99, 17_500, 61_777.77, 123_456.78, 8_000, 0,
];
const MODES = [null, null, null, "", "SEMI_MONTHLY", "MONTHLY", "MONTHLY", "QUARTERLY"] as const;

function fuzzEmployee(periodStart: string, periodEnd: string): EmployeeProfile {
  const o: Partial<Record<keyof EmployeeProfile, unknown>> = {
    monthly_rate: pick(MONTHLIES),
    hourly_rate: chance(0.2) ? pick([62.5, 100.25, 187.5, -10]) : 0,
    allow_sss: chance(0.85),
    allow_philhealth: chance(0.85),
    allow_pagibig: chance(0.85),
    allow_withholding_tax: chance(0.85),
    annualize_tax: chance(0.2),
    fixed_tax: chance(0.1) ? pick([1_000, 250.05]) : 0,
    percent_tax: chance(0.1) ? pick([10, 32.5]) : 0,
    fixed_sss_ee: chance(0.12) ? pick([250.05, 733.33, -10]) : 0,
    fixed_sss_er: chance(0.12) ? pick([510.05, 1_208.7]) : 0,
    fixed_philhealth_ee: chance(0.12) ? pick([128.01, 256.03]) : 0,
    fixed_philhealth_er: chance(0.12) ? pick([128.01, 450.55]) : 0,
    fixed_pagibig_ee: chance(0.12) ? pick([100.01, 200]) : 0,
    fixed_pagibig_er: chance(0.12) ? pick([100.01, 66.67]) : 0,
    sss_mode: pick(MODES),
    philhealth_mode: pick(MODES),
    pagibig_mode: pick(MODES),
  };
  // employment window: mostly clear of the period, sometimes clipping it
  const r = rnd();
  if (r < 0.12) {
    o.date_hired = addDays(periodStart, int(1, 10)); // hired mid-period
  } else if (r < 0.16) {
    o.date_hired = addDays(periodEnd, int(1, 20)); // hired after the period
  }
  const rr = rnd();
  if (rr < 0.1) {
    o.date_resigned = addDays(periodStart, int(2, 12)); // resigned mid-period
  } else if (rr < 0.13) {
    o.date_resigned = addDays(periodStart, -int(1, 15)); // resigned before it
  } else if (rr < 0.16) {
    // hired > resigned — the empty-days path
    o.date_hired = addDays(periodStart, 9);
    o.date_resigned = addDays(periodStart, 3);
  }
  return employee(o);
}

function fuzzPeriod(): { start: string; end: string } {
  const month = int(1, 11);
  const mm = String(month).padStart(2, "0");
  const last = lastDayOfMonth(2026, month);
  const r = rnd();
  if (r < 0.42) return { start: `2026-${mm}-01`, end: `2026-${mm}-15` };
  if (r < 0.84) return { start: `2026-${mm}-16`, end: `2026-${mm}-${String(last).padStart(2, "0")}` };
  // weird non-cutoff windows — computePayslip has no gate of its own
  const s = int(2, 14);
  const e = Math.min(s + int(0, 12), last);
  return {
    start: `2026-${mm}-${String(s).padStart(2, "0")}`,
    end: `2026-${mm}-${String(e).padStart(2, "0")}`,
  };
}

function fuzzEarningsOverride(): PayrollEarningOverride | undefined {
  if (chance(0.6)) return undefined;
  const o: Record<string, unknown> = {};
  if (chance(0.5)) o.night_differential = pick([0, 812.345, 1_000, -1]);
  else if (chance(0.1)) o.night_differential = "50"; // string is NOT a number in either engine
  if (chance(0.4)) o.leave_pay = pick([0, 500, 1_234.567, -20]);
  if (chance(0.4)) o.other_earnings = pick([0, 250.505, 999.99]);
  if (chance(0.6)) o.overtime_acknowledged = pick([true, false]);
  return o as PayrollEarningOverride;
}

for (let n = 0; n < FUZZ_VECTORS; n++) {
  const period = fuzzPeriod();
  const shift = fuzzShift();
  const input: Args = {
    employee: fuzzEmployee(period.start, period.end),
    logs: fuzzLogs(shift, period.start, period.end),
    shift,
    leaves: fuzzLeaves(period.start, period.end),
    periodStart: period.start,
    periodEnd: period.end,
    bonus: chance(0.7) ? 0 : pick([500, 123.456, 1_000.005, -200]),
  };
  const allowances = chance(0.6) ? undefined : pick([0, 1_500, 1_500.505, -50]);
  if (allowances !== undefined) input.allowancesOverride = allowances;
  const earnings = fuzzEarningsOverride();
  if (earnings !== undefined) input.earningsOverride = earnings;
  const settings = fuzzSettings(period.start, period.end);
  if (settings !== undefined) input.settings = settings;
  emit(input);
}

await Bun.write("tests/fixtures/golden-payslips.ndjson", lines.join("\n") + "\n");
console.log(
  JSON.stringify({ vectors: lines.length, tierA: tierACount, fuzz: lines.length - tierACount, stats }),
);
