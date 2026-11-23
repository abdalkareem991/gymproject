<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/common.php';

/** Require an active staff or administrator account. */
function staff_user(): array
{
    return auth_require_roles(['STAFF', 'ADMIN'], 'This area is restricted to staff accounts.');
}
