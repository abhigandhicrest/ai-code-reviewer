<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewRunFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_run_id',
        'path',
        'language',
        'code',
        'summary',
        'findings',
        'improved_code',
        'chunks_count',
    ];

    protected $casts = [
        'findings' => 'array',
    ];

    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }
}
