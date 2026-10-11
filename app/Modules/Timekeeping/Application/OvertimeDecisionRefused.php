<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/**
 * A refused overtime decision: the actor, the day, the minutes or the note
 * failed a rule, and nothing was written. Its message is the sentence the
 * screen shows.
 *
 * It exists so "Approve all pending" can tell a refusal apart from a failure.
 * A refused day is counted as skipped and the others go ahead; anything else
 * (RequiredAudit's RuntimeException, a QueryException) propagates and rolls
 * the whole bulk decision back, because an audit that could not be written
 * must never read as "skipped".
 */
final class OvertimeDecisionRefused extends RuntimeException {}
