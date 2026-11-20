<?php

namespace App\Services;

use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class QuotaService
{
    public static function defaultDailyLimit(): int
    {
        return (int) config('services.review.daily_quota', 20);
    }

    public static function limitFor(User $user): int
    {
        if ($user->is_admin) {
            return PHP_INT_MAX;
        }

        if ($user->daily_review_quota !== null) {
            return (int) $user->daily_review_quota;
        }

        return self::defaultDailyLimit();
    }

    public static function usedToday(User $user): int
    {
        return ReviewRun::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::today())
            ->count();
    }

    /**
     * @return array{limit: int, used: int, remaining: int|null, unlimited: bool}
     */
    public static function status(User $user): array
    {
        $unlimited = (bool) $user->is_admin;
        $limit = self::limitFor($user);
        $used = self::usedToday($user);

        return [
            'limit' => $unlimited ? 0 : $limit,
            'used' => $used,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'unlimited' => $unlimited,
        ];
    }

    public static function assertWithinQuota(User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        $status = self::status($user);
        if ($status['remaining'] <= 0) {
            throw ValidationException::withMessages([
                'code' => 'Daily review quota reached ('.$status['limit'].'/day). Try again tomorrow or ask an admin to raise your limit.',
            ]);
        }
    }
}
