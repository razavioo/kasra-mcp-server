<?php

namespace App\Services\Hr\Kasra;

use App\Models\User;

final class KasraAccess
{
    public function authorizeView(?User $user): void
    {
        abort_unless($user && ($user->hasAnyRole(['admin', 'founder']) || $user->can('attendance.manage') || $user->can('users.manage')), 403);
    }

    public function authorizeConfigure(?User $user): void
    {
        $this->authorizeView($user);
        abort_unless($user->hasAnyRole(['admin', 'founder']) || $user->can('integrations.manage') || $user->can('attendance.manage'), 403);
    }

    public function authorizeDecide(?User $user): void
    {
        abort_unless($user && ($user->hasAnyRole(['admin', 'founder']) || $user->can('attendance.manage')), 403);
    }
}
