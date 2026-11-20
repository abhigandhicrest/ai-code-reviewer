<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'focus_json',
        'extra_instructions',
        'is_system',
    ];

    protected $casts = [
        'focus_json' => 'array',
        'is_system' => 'boolean',
    ];

    public function reviewRuns(): HasMany
    {
        return $this->hasMany(ReviewRun::class);
    }
}
