<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CodingStandard extends Model
{
    use HasFactory;

    protected $fillable = [
        'language',
        'name',
        'rules_text',
        'linter',
        'linter_args',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * @return array<string, string> language => rules_text
     */
    public static function enabledRulesByLanguage(): array
    {
        return Cache::remember('coding_standards.enabled_map', 60, function () {
            return static::query()
                ->where('enabled', true)
                ->orderBy('language')
                ->get()
                ->mapWithKeys(fn (self $row) => [
                    $row->language => trim($row->name."\n".$row->rules_text),
                ])
                ->all();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('coding_standards.enabled_map');
    }

    public static function forLanguage(string $language): ?self
    {
        $lang = strtolower(trim($language));
        if (in_array($lang, ['js', 'jsx'], true)) {
            $lang = 'javascript';
        }
        if (in_array($lang, ['ts', 'tsx'], true)) {
            $lang = 'typescript';
        }
        if (in_array($lang, ['py'], true)) {
            $lang = 'python';
        }

        $row = static::query()->where('language', $lang)->where('enabled', true)->first();
        if ($row) {
            return $row;
        }

        return static::query()->where('language', 'general')->where('enabled', true)->first();
    }
}
