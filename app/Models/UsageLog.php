<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'review_run_id',
        'language',
        'code_chars',
        'estimated_tokens',
        'latency_ms',
        'findings_count',
        'success',
        'parsed',
        'mode',
        'model',
        'error_message',
        'ip_address',
    ];

    protected $casts = [
        'success' => 'boolean',
        'parsed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }
}
