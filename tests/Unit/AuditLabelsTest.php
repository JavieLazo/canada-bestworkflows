<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Platform\Audit\AuditLabels;

/**
 * The Audit Trail reads its Action and Entity columns as words: every
 * AuditEvent case has its own label and no two share one (so the action
 * filter never merges two events), and a code the map does not know still
 * reads as words instead of disappearing.
 */
it('labels every audit event, and nothing that is not one', function () {
    $cases = array_map(static fn (AuditEvent $e): string => $e->value, AuditEvent::cases());

    expect(array_keys(AuditLabels::ACTIONS))->toEqualCanonicalizing($cases);
});

it('gives no two audit events the same label', function () {
    $labels = array_values(AuditLabels::ACTIONS);

    expect(array_unique($labels))->toHaveCount(count($labels));
});

it('reads a known action and entity as their labels', function () {
    expect(AuditLabels::action('REQUEST_REJECTED'))->toBe('Leave/OB request disapproved')
        ->and(AuditLabels::action(AuditEvent::LOGIN_SUCCESS->value))->toBe('Signed in')
        ->and(AuditLabels::entity('leave_travel_requests'))->toBe('Leave/OB request')
        ->and(AuditLabels::entity('inventory_category'))->toBe('Inventory category')
        ->and(AuditLabels::entity('users'))->toBe(AuditLabels::entity('user_accounts'));
});

it('turns an unknown code into words rather than hiding it', function () {
    expect(AuditLabels::action('LEGACY_THING_DONE'))->toBe('Legacy thing done')
        ->and(AuditLabels::entity('legacy_rows'))->toBe('Legacy rows')
        ->and(AuditLabels::action(''))->toBe('');
});

it('reads a blank entity as none', function () {
    expect(AuditLabels::entity(null))->toBeNull()
        ->and(AuditLabels::entity(''))->toBeNull()
        ->and(AuditLabels::entity('  '))->toBeNull();
});
