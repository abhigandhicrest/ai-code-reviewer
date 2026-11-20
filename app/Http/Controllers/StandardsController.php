<?php

namespace App\Http\Controllers;

use App\Models\CodingStandard;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandardsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->is_admin, 403);

        return view('standards.index', [
            'standards' => CodingStandard::query()->orderBy('language')->get(),
            'linters' => ['none', 'phpcs', 'ruff', 'eslint'],
        ]);
    }

    public function update(Request $request, CodingStandard $standard): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rules_text' => ['required', 'string', 'max:20000'],
            'linter' => ['required', 'in:none,phpcs,ruff,eslint'],
            'linter_args' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $standard->update([
            'name' => $validated['name'],
            'rules_text' => $validated['rules_text'],
            'linter' => $validated['linter'],
            'linter_args' => $validated['linter_args'] ?? null,
            'enabled' => $request->boolean('enabled'),
        ]);

        CodingStandard::clearCache();

        AuditLogger::log($request->user(), 'standards.updated', 'coding_standard', $standard->id, [
            'language' => $standard->language,
            'enabled' => $standard->enabled,
            'linter' => $standard->linter,
        ], $request);

        return redirect()->route('standards.index')->with('status', 'Updated '.$standard->language.' standards.');
    }
}
