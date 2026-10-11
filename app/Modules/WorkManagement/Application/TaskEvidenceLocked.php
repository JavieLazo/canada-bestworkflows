<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use RuntimeException;

/**
 * A refusal to store task evidence, decided under the task-row lock INSIDE
 * AttachmentStore's persist callback — the task moved, its mode changed, the
 * member left the crew, or their evidence is locked.
 *
 * It exists so AttachSubmission can tell this apart from a storage or
 * database failure: both arrive as a Throwable out of AttachmentStore, which
 * has already discarded the uploaded bytes. A failure is logged and reported
 * as "Upload failed"; this one carries a message meant for the member and is
 * passed through as it is.
 */
final class TaskEvidenceLocked extends RuntimeException {}
