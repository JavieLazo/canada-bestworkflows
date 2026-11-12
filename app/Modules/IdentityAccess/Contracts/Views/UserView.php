<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * Account administration data; credentials and session tokens are never selected.
 *
 * @property-read int $id
 * @property-read string $name
 * @property-read string|null $account_name  the name typed for an account with no employee record
 * @property-read string $email
 * @property-read string|null $role_id
 * @property-read string|null $employee_id
 * @property-read bool $is_active
 */
final readonly class UserView extends Record {}
