<?php

namespace Database\Seeders;

use App\Models\PromptProfile;
use Illuminate\Database\Seeder;

class PromptProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            [
                'slug' => 'general',
                'name' => 'General',
                'description' => 'Balanced bugs, security, and improvements.',
                'focus_json' => ['bugs', 'security', 'improve'],
                'extra_instructions' => 'Be concise and prioritize correctness and safety.',
                'is_system' => true,
            ],
            [
                'slug' => 'security',
                'name' => 'Security',
                'description' => 'Focus on injection, auth, secrets, and unsafe patterns.',
                'focus_json' => ['security', 'bugs'],
                'extra_instructions' => 'Prioritize OWASP-style issues: injection, XSS, CSRF, authZ/authN flaws, secret leakage, insecure deserialization, path traversal. Ignore pure style nits.',
                'is_system' => true,
            ],
            [
                'slug' => 'laravel-blade',
                'name' => 'Laravel / Blade',
                'description' => 'Laravel and Blade template review focus.',
                'focus_json' => ['bugs', 'security', 'improve'],
                'extra_instructions' => 'Emphasize Blade escaping ({!! !!} risks), mass assignment, N+1 queries, validation, middleware/auth gates, queue failures, and unsafe raw DB/SQL.',
                'is_system' => true,
            ],
            [
                'slug' => 'performance',
                'name' => 'Performance',
                'description' => 'Hot paths, algorithms, and resource usage.',
                'focus_json' => ['improve', 'bugs'],
                'extra_instructions' => 'Focus on algorithmic complexity, unnecessary loops, N+1 I/O, memory growth, blocking calls, and caching opportunities. Mention concrete complexity when possible.',
                'is_system' => true,
            ],
        ];

        foreach ($profiles as $profile) {
            PromptProfile::updateOrCreate(
                ['slug' => $profile['slug']],
                $profile
            );
        }
    }
}
