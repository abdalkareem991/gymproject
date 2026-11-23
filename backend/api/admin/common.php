<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/common.php';

/** Require an active administrator account for analytics and exports. */
function admin_user(): array
{
    return auth_require_roles(['ADMIN'], 'This area is restricted to administrators.');
}
