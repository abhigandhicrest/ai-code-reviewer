<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuppressedFinding extends Model
{
    protected $fillable = [
        'user_id',
        'fingerprint',
        'file_path',
        'title',
        'project_root',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
