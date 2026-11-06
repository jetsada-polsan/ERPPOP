<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

final class FinanceBranches
{
    public static function allows(User $user, int $branchId, string $permission = 'finance.manage'): bool
    {
        return $user->canSeeAllReportBranches() || $user->canAccessBranch($branchId, $permission);
    }

    public static function available(User $user, string $permission = 'finance.manage'): Collection
    {
        return Branch::orderBy('code')->get()->filter(fn ($branch) => self::allows($user, (int) $branch->id, $permission))->values();
    }
}
