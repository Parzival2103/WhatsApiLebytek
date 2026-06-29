<?php

namespace App\Policies;

use App\Models\User;

class DashboardPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->denyByDefault($user, 'dashboard.ver');
    }
}
