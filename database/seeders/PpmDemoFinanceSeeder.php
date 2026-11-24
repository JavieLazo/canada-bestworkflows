<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFinance;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectFunding;
use App\Models\ProjectFundingInstallment;
use App\Models\User;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PpmDemoFinanceSeeder extends PpmDemoRecords
{
    /** @param Collection<int, User> $people */
    public function seedProject(Project $project, User $owner, Collection $people, int $number): void
    {
        if ($number % 3 === 0 && $number % 9 !== 0) {
            return;
        }
        $p = $project->id;
        $currency = $number % 7 === 0 ? 'USD' : ($number % 11 === 0 ? 'EUR' : 'CAD');
        $this->keep(ProjectFinance::class, $p.':finance', ['project_id' => $p, 'currency' => $currency, 'opening_on' => $number % 5 === 0 ? null : $this->dateKey(190),
            'opening_minor' => $number % 5 === 0 ? null : 250000, 'opening_reference' => $number % 5 === 0 ? null : 'DEMO-OPENING-'.$number,
            'opening_notes' => 'Synthetic opening balance for review.', 'opening_revision' => $number % 5 === 0 ? 0 : 1]);
        foreach ([1 => 'EDITOR', 2 => 'VIEWER'] as $index => $role) {
            $this->keep(ProjectFinanceMember::class, $p.':finance-member:'.$index, ['project_id' => $p, 'user_id' => $people[$index]->id, 'role' => $role]);
        }
        if ($number % 9 === 0) {
            $this->keep(ProjectCostCategory::class, $p.':category:0', ['project_id' => $p, 'name' => 'General']);

            return;
        }
        $funding = self::id($p.':funding');
        $this->keep(ProjectFunding::class, $p.':funding', ['project_id' => $p, 'name' => 'Demo delivery funding', 'provider' => 'Demo programme partner',
            'committed_minor' => 8000000 + $number * 10000, 'expected_on' => $this->dateKey(-7), 'notes' => 'Synthetic commitment, not a receipt.',
            'status' => 'ACTIVE', 'revision' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        foreach (range(0, 2) as $i) {
            $this->keep(ProjectFundingInstallment::class, $p.':installment:'.$i, ['project_id' => $p, 'funding_id' => $funding, 'name' => 'Demo installment '.($i + 1),
                'planned_minor' => 2000000, 'planned_on' => $this->dateKey(45 - $i * 40), 'conditions' => $i === 2 ? 'Demo acceptance milestone.' : null,
                'status' => 'ACTIVE', 'revision' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        }
        foreach (['CASH', 'IN_KIND'] as $kind) {
            $this->keep(ProjectContribution::class, $p.':contribution:'.$kind, ['project_id' => $p, 'name' => $kind === 'CASH' ? 'Demo partner cash' : 'Demo equipment support',
                'contributor' => 'Demo community partner', 'kind' => $kind, 'unit' => $kind === 'IN_KIND' ? 'kits' : null,
                'quantity_scaled' => $kind === 'IN_KIND' ? 200000 : null, 'committed_minor' => $kind === 'CASH' ? 1200000 : 600000,
                'valuation_basis' => $kind === 'IN_KIND' ? 'Demo estimate: 20 kits at 300 each. Not spendable cash.' : null,
                'expected_on' => $this->dateKey(-14), 'status' => 'ACTIVE', 'revision' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        }
        foreach ([0 => 300000, 1 => null] as $i => $value) {
            $deliveryId = self::id($p.':delivery:'.$i);
            $this->keep(ProjectContributionDelivery::class, $p.':delivery:'.$i, ['project_id' => $p, 'contribution_id' => self::id($p.':contribution:IN_KIND'),
                'occurred_on' => $this->dateKey(5 + $i), 'quantity_scaled' => 100000, 'value_minor' => $value,
                'valuation_basis' => $value === null ? null : 'Demo valuation for ten received kits.', 'reference' => 'DEMO-DELIVERY-'.$number.'-'.$i, 'created_by' => $owner->id]);
            $this->attachment($p, $deliveryId, $owner->id, 'CONTRIBUTION_DELIVERY');
        }
        $categories = ['Materials', 'Services', 'Travel'];
        foreach ($categories as $i => $name) {
            $this->keep(ProjectCostCategory::class, $p.':category:'.$i, ['project_id' => $p, 'name' => $name]);
        }
        foreach ([1 => 'APPROVED', 2 => 'DRAFT'] as $version => $status) {
            $budgetId = self::id($p.':budget:'.$version);
            $lines = [];
            foreach (range(0, $number === 1 ? 35 : 5) as $i) {
                $unit = 50000 + $number * 100 + $i * 5000;
                $quantity = FinanceAmount::quantity((string) (3 + $i));
                $total = FinanceAmount::calculated($quantity, $version, $unit);
                $lines[] = ['project_id' => $p, 'budget_id' => $budgetId, 'category_id' => self::id($p.':category:'.($i % 3)),
                    'group_id' => self::id($p.':group:'.($i % 3)), 'category_label' => $categories[$i % 3], 'group_label' => ['Discovery', 'Delivery', 'Acceptance'][$i % 3],
                    'funding_id' => $funding, 'source_label' => 'Demo delivery funding', 'label' => ['Field equipment', 'Implementation support', 'Site transport', 'Training materials', 'Acceptance checks', 'Handover support'][$i % 6].($i < 6 ? '' : ' batch '.(intdiv($i, 6) + 1)),
                    'basis' => 'CALCULATED', 'quantity_scaled' => $quantity, 'frequency' => $version, 'unit_minor' => $unit, 'total_minor' => $total, 'notes' => 'Explicit synthetic cost assumptions.'];
            }
            $this->keep(ProjectBudget::class, $p.':budget:'.$version, ['project_id' => $p, 'version' => $version, 'revision' => 7, 'status' => $status,
                'reason' => $version === 1 ? 'Demo approved baseline.' : 'Demo working revision with expanded delivery.', 'total_minor' => $status === 'APPROVED' ? array_sum(array_column($lines, 'total_minor')) : null,
                'created_by' => $owner->id, 'approved_by' => $status === 'APPROVED' ? $owner->id : null, 'approved_at' => $status === 'APPROVED' ? $this->at(35, 10, 0) : null]);
            foreach ($lines as $i => $line) {
                $this->keep(ProjectBudgetLine::class, $p.':budget:'.$version.':line:'.$i, $line);
            }
        }
        foreach (range(0, $number === 1 ? 31 : 9) as $i) {
            $key = $p.':cash:'.$i;
            $id = self::id($key);
            $receipt = $i < 3;
            $amount = $receipt ? 1400000 : (80000 + $i * 15000 + $number * 100) * ($number % 5 === 0 ? 3 : 1);
            $voided = $i === 8;
            $unlinked = $i === 2;
            $this->keep(ProjectCashEntry::class, $key, ['project_id' => $p, 'category_id' => self::id($p.':category:'.($i % 3)), 'category_label' => $categories[$i % 3],
                'group_id' => self::id($p.':group:'.($i % 3)), 'group_label' => ['Discovery', 'Delivery', 'Acceptance'][$i % 3], 'allocation_revision' => $unlinked ? 0 : 1,
                'kind' => $receipt ? 'RECEIPT' : 'EXPENSE', 'occurred_on' => $i === 9 ? $this->dateKey(0) : CarbonImmutable::parse($this->dateKey(0))->subMonthsNoOverflow($i % 6)->subDays(2)->format('Y-m-d'),
                'amount_minor' => $amount, 'description' => ($receipt ? 'Demo funding receipt ' : 'Demo delivery payment ').($i + 1),
                'party' => $receipt ? 'Demo funding partner' : 'Demo supplier', 'reference' => 'DEMO-CASH-'.$number.'-'.$i, 'created_by' => $owner->id,
                'replaces_id' => $i === 9 ? self::id($p.':cash:8') : null, 'voided_at' => $voided ? $this->at(1, 12, 0) : null,
                'voided_by' => $voided ? $owner->id : null, 'void_reason' => $voided ? 'Demo correction: replaced with the verified invoice amount.' : null]);
            if (! $unlinked) {
                foreach ([0, 1] as $split) {
                    $part = $split === 0 ? intdiv($amount, 2) : $amount - intdiv($amount, 2);
                    $this->keep(ProjectCashAllocation::class, $key.':split:'.$split, ['project_id' => $p, 'entry_id' => $id, 'revision' => 1,
                        'source_kind' => $i === 1 ? 'CONTRIBUTION' : 'FUNDING', 'source_id' => $i === 1 ? self::id($p.':contribution:CASH') : $funding,
                        'source_label' => $i === 1 ? 'Demo partner cash' : 'Demo delivery funding', 'installment_id' => $i === 0 ? self::id($p.':installment:0') : null,
                        'installment_label' => $i === 0 ? 'Demo installment 1' : null, 'group_id' => self::id($p.':group:'.$split),
                        'group_label' => ['Discovery', 'Delivery'][$split], 'amount_minor' => $part, 'created_by' => $owner->id]);
                }
            }
            if ($i === 9) {
                $this->attachment($p, $id, $owner->id, 'CASH_ENTRY');
            }
        }
    }
}
