<?php

namespace App\Services;

use App\Models\SuppressedFinding;
use App\Models\User;

class SuppressService
{
    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function filter(User $user, array $findings): array
    {
        $prints = SuppressedFinding::query()
            ->where('user_id', $user->id)
            ->pluck('fingerprint')
            ->all();
        if ($prints === []) {
            return $findings;
        }
        $set = array_flip($prints);

        return array_values(array_filter($findings, function ($finding) use ($set) {
            if (! is_array($finding)) {
                return false;
            }
            $fp = FindingLocator::fingerprint($finding);

            return ! isset($set[$fp]);
        }));
    }

    public static function suppress(User $user, array $finding, ?string $projectRoot = null): SuppressedFinding
    {
        $fp = FindingLocator::fingerprint($finding);

        return SuppressedFinding::query()->updateOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $fp],
            [
                'file_path' => $finding['file_path'] ?? null,
                'title' => $finding['title'] ?? null,
                'project_root' => $projectRoot,
            ]
        );
    }
}
