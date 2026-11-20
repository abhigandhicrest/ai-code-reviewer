<?php

namespace App\Http\Controllers;

use App\Models\ReviewRun;
use App\Models\User;
use App\Services\ApplyFixService;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $query = ReviewRun::query()->with(['user', 'promptProfile'])->latest();
        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        return view('history.index', [
            'runs' => $query->paginate(20)->withQueryString(),
            'isAdmin' => (bool) $user->is_admin,
            'quota' => QuotaService::status($user),
        ]);
    }

    public function show(Request $request, ReviewRun $reviewRun): View
    {
        $this->authorizeRun($request->user(), $reviewRun);
        $reviewRun->load(['files', 'promptProfile']);

        AuditLogger::log($request->user(), 'history.viewed', 'review_run', $reviewRun->id, [], $request);

        return view('history.show', [
            'run' => $reviewRun,
            'quota' => QuotaService::status($request->user()),
            'print' => $request->boolean('print'),
            'canApply' => ApplyFixService::canApply($reviewRun),
        ]);
    }

    public function compare(Request $request): View
    {
        $request->validate([
            'a' => ['required', 'integer', 'exists:review_runs,id'],
            'b' => ['required', 'integer', 'exists:review_runs,id', 'different:a'],
        ]);

        $a = ReviewRun::query()->with('files')->findOrFail($request->integer('a'));
        $b = ReviewRun::query()->with('files')->findOrFail($request->integer('b'));
        $this->authorizeRun($request->user(), $a);
        $this->authorizeRun($request->user(), $b);

        $mapA = $this->findingMap($a->findings ?? []);
        $mapB = $this->findingMap($b->findings ?? []);

        $onlyA = array_values(array_diff_key($mapA, $mapB));
        $onlyB = array_values(array_diff_key($mapB, $mapA));
        $shared = array_values(array_intersect_key($mapA, $mapB));

        return view('history.compare', [
            'a' => $a,
            'b' => $b,
            'onlyA' => $onlyA,
            'onlyB' => $onlyB,
            'shared' => $shared,
        ]);
    }

    public function exportMarkdown(Request $request, ReviewRun $reviewRun): Response
    {
        $this->authorizeRun($request->user(), $reviewRun);
        $reviewRun->load('files');

        AuditLogger::log($request->user(), 'history.exported', 'review_run', $reviewRun->id, [
            'format' => 'markdown',
        ], $request);

        $md = $this->toMarkdown($reviewRun);
        $prefix = $reviewRun->isGenerate() ? 'generate' : 'review';

        return response($md, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$prefix.'-'.$reviewRun->id.'.md"',
        ]);
    }

    public function rerun(Request $request, ReviewRun $reviewRun): RedirectResponse
    {
        $this->authorizeRun($request->user(), $reviewRun);

        if ($reviewRun->isGenerate()) {
            $instruction = $reviewRun->title ?: '';
            if (preg_match('/^Instruction:\s*\n(.*)/s', (string) $reviewRun->code, $m)) {
                $instruction = trim($m[1]);
            }

            return redirect()
                ->route('generate.index')
                ->withInput([
                    'instruction' => $instruction,
                    'language' => $reviewRun->language === 'multi' ? 'php' : $reviewRun->language,
                    'prompt_profile_id' => $reviewRun->prompt_profile_id,
                ])
                ->with('status', 'Loaded generate #'.$reviewRun->id.'. Click Generate to run again.');
        }

        return redirect()
            ->route('review.index')
            ->withInput([
                'code' => $reviewRun->code,
                'language' => $reviewRun->language === 'multi' ? 'php' : $reviewRun->language,
                'prompt_profile_id' => $reviewRun->prompt_profile_id,
                'project_path' => $reviewRun->project_root,
            ])
            ->with('status', 'Loaded review #'.$reviewRun->id.' into the editor. Click Review code to run again.');
    }

    public function apply(Request $request, ReviewRun $reviewRun): RedirectResponse
    {
        $this->authorizeRun($request->user(), $reviewRun);

        $request->validate([
            'confirm' => ['accepted'],
            'project_root' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = ApplyFixService::apply(
            $reviewRun,
            $request->user(),
            $request->input('project_root'),
            $request
        );

        $status = sprintf(
            'Applied %d file(s), skipped %d, backed up under storage/app/acr-backups/%d/.',
            $result['applied'],
            $result['skipped'],
            $reviewRun->id
        );
        if ($result['errors'] !== []) {
            $status .= ' Issues: '.implode('; ', array_slice($result['errors'], 0, 3));
        }

        return redirect()
            ->route('history.show', $reviewRun)
            ->with('status', $status);
    }

    private function authorizeRun(User $user, ReviewRun $reviewRun): void
    {
        abort_unless($user->is_admin || $reviewRun->user_id === $user->id, 403);
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, array<string, mixed>>
     */
    private function findingMap(array $findings): array
    {
        $map = [];
        foreach ($findings as $finding) {
            $key = strtolower(trim(
                ($finding['severity'] ?? '').'|'.
                ($finding['file_path'] ?? '').'|'.
                ($finding['title'] ?? '').'|'.
                mb_substr((string) ($finding['explanation'] ?? ''), 0, 80)
            ));
            $map[$key] = $finding;
        }

        return $map;
    }

    private function toMarkdown(ReviewRun $run): string
    {
        $kind = $run->isGenerate() ? 'Generate' : 'Review';
        $lines = [
            '# '.$kind.' #'.$run->id,
            '',
            '- When: '.$run->created_at,
            '- Language: '.$run->language,
            '- Source: '.$run->source_type,
            '- Files: '.$run->files_count,
            '- Chunks: '.$run->chunks_count,
            '- Status: '.($run->success ? 'ok' : 'failed'),
            '',
            '## '.($run->isGenerate() ? 'Notes' : 'Summary'),
            '',
            $run->summary ?: '_None_',
            '',
        ];

        if (! $run->isGenerate()) {
            $lines[] = '## Findings';
            $lines[] = '';
            foreach ($run->findings ?? [] as $finding) {
                $lines[] = '### ['.strtoupper((string) ($finding['severity'] ?? 'medium')).'] '.($finding['title'] ?? 'Issue');
                if (! empty($finding['file_path'])) {
                    $lines[] = '- File: `'.$finding['file_path'].'`';
                }
                if (! empty($finding['line_hint'])) {
                    $lines[] = '- Location: '.$finding['line_hint'];
                }
                $lines[] = '';
                $lines[] = (string) ($finding['explanation'] ?? '');
                $lines[] = '';
            }
        }

        $generatedFiles = $run->files->filter(fn ($f) => filled($f->improved_code));
        if ($generatedFiles->count()) {
            $lines[] = '## Generated code';
            $lines[] = '';
            foreach ($generatedFiles as $file) {
                $lines[] = '### `'.$file->path.'`';
                $lines[] = '';
                $lines[] = '```'.$file->language;
                $lines[] = $file->improved_code;
                $lines[] = '```';
                $lines[] = '';
            }
        } elseif ($run->improved_code) {
            $lines[] = '## Generated code';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $run->improved_code;
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
