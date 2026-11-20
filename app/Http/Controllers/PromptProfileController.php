<?php

namespace App\Http\Controllers;

use App\Models\PromptProfile;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromptProfileController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->is_admin, 403);

        return view('profiles.index', [
            'profiles' => PromptProfile::query()->orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'extra_instructions' => ['nullable', 'string', 'max:5000'],
            'focus' => ['nullable', 'array'],
            'focus.*' => ['in:bugs,security,style,improve'],
        ]);

        $slug = Str::slug($validated['name']);
        $base = $slug;
        $i = 1;
        while (PromptProfile::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        $profile = PromptProfile::create([
            'slug' => $slug,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'extra_instructions' => $validated['extra_instructions'] ?? null,
            'focus_json' => $validated['focus'] ?? ['bugs', 'security', 'improve'],
            'is_system' => false,
        ]);

        AuditLogger::log($request->user(), 'profile.created', 'prompt_profile', $profile->id, [
            'slug' => $profile->slug,
        ], $request);

        return redirect()->route('profiles.index')->with('status', 'Profile created.');
    }

    public function destroy(Request $request, PromptProfile $profile): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($profile->is_system, 422, 'System profiles cannot be deleted.');

        AuditLogger::log($request->user(), 'profile.deleted', 'prompt_profile', $profile->id, [
            'slug' => $profile->slug,
        ], $request);

        $profile->delete();

        return redirect()->route('profiles.index')->with('status', 'Profile deleted.');
    }
}
