<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/common.php';

/** Require an active member account. */
function member_user(): array
{
    return auth_require_roles(['MEMBER'], 'This area is available to member accounts.');
}
