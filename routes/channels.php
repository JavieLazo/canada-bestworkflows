<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels — the fourth enforcement layer
|--------------------------------------------------------------------------
| guard.ts:13-17 names three layers: the route gate, the module grant, and
| row scoping. A websocket adds a fourth surface, and it is the only one where
| a browser holds an open subscription rather than asking a question — so it
| gets the same treatment as the others: authorised server-side, from the
| session, against Access, with the ROWS_NONE case failing closed.
|
| WHAT SUBSCRIBING ACTUALLY BUYS AN ATTACKER: nothing but timing. The only
| event these channels carry is App\Events\RealtimeChange, which has no
| payload — a subscriber learns that *something* changed, then has to ask the
| server for it through the same scoped queries as everyone else. These
| callbacks are still written as if the payload were sensitive, because the
| day someone adds one is not the day to discover the channel was open.
|
| Every callback mirrors the scope of the screen that listens on it. Where the
| rule is "the same as ScopesToActor", it is written as a `match` on
| Access::rowVisibility() with all three arms spelled out, so a fourth
| visibility state added later fails loudly here too instead of defaulting to
| a subscription.
*/

/**
 * Every punch, for a feed that shows the whole organisation's.
 *
 * The widening modules are EDTR and PAYROLL — the same pair
 * App\Livewire\Edtr\LiveFeed scopes its rows with (api/attendance/route.ts:25),
 * and widening this list would hand a self-only viewer the timing of everyone
 * else's punches.
 */
Broadcast::channel('attendance', static function (User $user): bool {
    return Access::forActor($user)->rowVisibility(ModuleCode::EDTR, ModuleCode::PAYROLL)
        === Access::ROWS_ALL;
});

/**
 * One employee's punches: their own account, or anyone who may read the whole
 * organisation's attendance anyway.
 *
 * An account with no employee link reaches the ROWS_NONE arm and is refused —
 * the subscription equivalent of the empty collection ScopesToActor hands the
 * same account.
 */
Broadcast::channel('attendance.employee.{employeeId}', static function (User $user, string $employeeId): bool {
    $actor = Access::forActor($user);

    return match ($actor->rowVisibility(ModuleCode::EDTR, ModuleCode::PAYROLL)) {
        Access::ROWS_ALL => true,
        Access::ROWS_OWN => $actor->ownEmployeeId() === $employeeId,
        Access::ROWS_NONE => false,
    };
});

/**
 * One account's own notifications — the bell and /notifications.
 *
 * "An inbox is not a module, every signed-in account has one"
 * (notifications/page.tsx:15-21), so the gate is not a grant: it is identity,
 * and identity alone. No role, however wide, subscribes to somebody else's
 * inbox — all_data widens which ROWS you may read, never whose bell you may
 * hear.
 */
Broadcast::channel('user.{userId}', static function (User $user, string $userId): bool {
    return (int) $user->getKey() === (int) $userId;
});
