<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        ?User $user,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = [],
        ?Request $request = null,
    ): void {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta_json' => $meta ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }
}
