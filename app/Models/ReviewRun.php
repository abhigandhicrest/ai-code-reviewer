<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReviewRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'prompt_profile_id',
        'language',
        'code',
        'title',
        'summary',
        'review',
        'findings',
        'improved_code',
        'parsed',
        'success',
        'chunks_count',
        'files_count',
        'code_chars',
        'estimated_tokens',
        'latency_ms',
        'findings_count',
        'mode',
        'source_type',
        'project_root',
        'model',
        'error_message',
    ];

    protected $casts = [
        'findings' => 'array',
        'parsed' => 'boolean',
        'success' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promptProfile(): BelongsTo
    {
        return $this->belongsTo(PromptProfile::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReviewRunFile::class);
    }

    public function usageLog(): HasOne
    {
        return $this->hasOne(UsageLog::class);
    }

    public function displayTitle(): string
    {
        if ($this->title) {
            return ($this->source_type === 'generate' ? 'Generate: ' : '').$this->title;
        }

        $firstLine = trim(strtok((string) $this->code, "\n") ?: '');
        if ($firstLine === '') {
            return ($this->source_type === 'generate' ? 'Generate #' : 'Review #').$this->id;
        }

        $prefix = $this->source_type === 'generate' ? 'Generate: ' : '';

        return $prefix.mb_strimwidth($firstLine, 0, 72, '…');
    }

    public function isGenerate(): bool
    {
        return $this->source_type === 'generate';
    }

    public function findingKey(array $finding): string
    {
        return strtolower(trim(
            ($finding['severity'] ?? '').'|'.
            ($finding['file_path'] ?? '').'|'.
            ($finding['title'] ?? '').'|'.
            mb_substr((string) ($finding['explanation'] ?? ''), 0, 80)
        ));
    }
}
