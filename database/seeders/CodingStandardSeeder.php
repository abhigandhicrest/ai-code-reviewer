<?php

namespace Database\Seeders;

use App\Models\CodingStandard;
use Illuminate\Database\Seeder;

class CodingStandardSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'language' => 'php',
                'name' => 'PSR-12 + Laravel conventions',
                'linter' => 'phpcs',
                'linter_args' => '--standard=PSR12',
                'rules_text' => implode("\n", [
                    '- Follow PSR-12: braces, indentation (4 spaces), line length preference ~120, visibility on methods/properties.',
                    '- Use strict types where project already does; declare return types and parameter types when practical.',
                    '- Prefer Laravel conventions: Form Requests, Eloquent over raw SQL, Blade escaping {{ }} not {!! !!} unless intentional.',
                    '- Avoid unused imports/variables; meaningful names; no dead code.',
                    '- Keep controllers thin; business logic in services/actions.',
                ]),
            ],
            [
                'language' => 'python',
                'name' => 'PEP 8',
                'linter' => 'ruff',
                'linter_args' => 'check --select E,F,W',
                'rules_text' => implode("\n", [
                    '- Follow PEP 8: 4-space indent, snake_case functions/vars, CapWords classes, module imports ordered.',
                    '- Prefer explicit exceptions; avoid bare except; close resources with context managers.',
                    '- Type hints encouraged for public functions; docstrings for non-obvious APIs.',
                    '- No unused imports; avoid mutable default arguments.',
                ]),
            ],
            [
                'language' => 'javascript',
                'name' => 'ESLint recommended',
                'linter' => 'eslint',
                'linter_args' => null,
                'rules_text' => implode("\n", [
                    '- Prefer const/let over var; === over ==; avoid unused vars.',
                    '- Handle promises with await/catch; no floating promises in async flows.',
                    '- Avoid eval; sanitize any DOM sinks; consistent naming (camelCase).',
                    '- Keep functions small; no unreachable code.',
                ]),
            ],
            [
                'language' => 'typescript',
                'name' => 'ESLint + TypeScript practices',
                'linter' => 'eslint',
                'linter_args' => null,
                'rules_text' => implode("\n", [
                    '- Prefer explicit types on exports/public APIs; avoid any unless justified.',
                    '- Same JS hygiene: const/let, ===, no unused vars, proper async error handling.',
                    '- Prefer unknown over any for untrusted input; narrow with type guards.',
                ]),
            ],
            [
                'language' => 'general',
                'name' => 'General engineering standards',
                'linter' => 'none',
                'linter_args' => null,
                'rules_text' => implode("\n", [
                    '- Clear naming; single responsibility; DRY without over-abstraction.',
                    '- Validate inputs at boundaries; fail fast with useful errors.',
                    '- No secrets in source; handle errors; clean up resources.',
                ]),
            ],
        ];

        foreach ($rows as $row) {
            CodingStandard::query()->updateOrCreate(
                ['language' => $row['language']],
                array_merge($row, ['enabled' => true])
            );
        }

        CodingStandard::clearCache();
    }
}
