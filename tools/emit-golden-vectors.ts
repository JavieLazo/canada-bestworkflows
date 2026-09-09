/**
 * Emits golden vectors from the REAL TypeScript statutory engine.
 *
 * Run:  bun tools/emit-golden-vectors.ts
 *
 * This imports ../copems/src/lib/payroll-statutory.ts directly — it is not a
 * transcription, so it cannot drift from the engine it is meant to pin down.
 *
 * Generation is ADVERSARIAL, not a tidy sweep. A tidy sweep of "bracket
 * boundaries at +/-0.01" was measured to score ZERO detections against a PHP
 * port using the forbidden round($x, 2). The exposure that matters lives in
 * the ordinary middle of the range: sweeping every whole-peso salary from
 * 10,000 to 100,000, round($x,2) mispays the PhilHealth employee line on
 * 3,242 of 90,001 salaries. So tier 1 is every integer in that decade.
 */
import {
  sssMonthlyContribution,
  philhealthMonthlyContribution,
  pagibigMonthlyContribution,
  semiMonthlyWithholdingTax,
  annualIncomeTax,
  annualizedSemiMonthlyWithholdingTax,
} from "../../copems/src/lib/payroll-statutory";

const seen = new Set<number>();
const inputs: number[] = [];

function push(v: number) {
  if (!Number.isFinite(v)) return;
  const k = Math.round(v * 100) / 100;
  if (seen.has(k)) return;
  seen.add(k);
  inputs.push(k);
}

// Tier 1 — every integer peso across the working decade. The load-bearing tier.
for (let s = 10_000; s <= 100_000; s++) push(s);

// Tier 2 — every centavo across each BIR semi-monthly bracket boundary.
for (const b of [10_417, 16_667, 33_333, 83_333, 333_333]) {
  for (let c = -100; c <= 100; c++) push(b + c / 100);
}

// Tier 3 — every centavo across each TRAIN annual bracket boundary.
for (const b of [250_000, 400_000, 800_000, 2_000_000, 8_000_000]) {
  for (let c = -100; c <= 100; c++) push(b + c / 100);
}

// Tier 4 — SSS MSC steps every 500, probed either side of the rounding edge.
for (let msc = 4_000; msc <= 36_000; msc += 500) {
  for (const d of [-250.01, -250, -249.99, -0.01, 0, 0.01, 249.99, 250, 250.01]) push(msc + d);
}

// Tier 5 — every centavo across one full decade, to catch half-centavo
// manufacture anywhere in a continuous range rather than only at edges.
for (let c = 0; c <= 10_000; c++) push(10_000 + c / 100);

// Tier 6 — Pag-IBIG and PhilHealth floors/caps, and degenerate inputs.
for (const v of [
  0, 0.001, 0.01, 1, 1_499.99, 1_500, 1_500.01, 4_999.99, 5_000,
  9_999.99, 10_000, 10_000.01, 10_241, 34_999.99, 35_000, 35_000.01,
  99_999.99, 100_000, 100_000.01, 250_000, 1_000_000, 8_000_000.01,
  -1, -0.01, -100_000,
]) push(v);

/**
 * The SEMI_MONTHLY path of payroll.ts contribution(): an already-2dp-rounded
 * monthly table value halved and re-rounded at the call site. THIS is where the
 * documented divergence lives - the statutory table alone does not expose it,
 * because halving an odd last centavo is what manufactures the exact half-centavo.
 */
const money = (n: number) => Math.round((Number.isFinite(n) ? n : 0) * 100) / 100;
const semi = (monthly: number) => money(money(monthly) / 2);

const out: string[] = [];
for (const input of inputs) {
  const sss = sssMonthlyContribution(input);
  const ph = philhealthMonthlyContribution(input);
  const pi = pagibigMonthlyContribution(input);
  out.push(
    JSON.stringify([
      input,
      sss.employee, sss.employer,
      ph.employee, ph.employer,
      pi.employee, pi.employer,
      semiMonthlyWithholdingTax(input),
      annualIncomeTax(input),
      annualizedSemiMonthlyWithholdingTax(input),
      semi(sss.employee), semi(sss.employer),
      semi(ph.employee), semi(ph.employer),
      semi(pi.employee), semi(pi.employer),
    ]),
  );
}

await Bun.write("tests/fixtures/golden-statutory.ndjson", out.join("\n") + "\n");
console.log(JSON.stringify({ vectors: out.length }));
