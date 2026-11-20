<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class HealthController extends Controller
{
    public function index(Request $request): View
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'fastapi' => $this->checkFastApi(),
            'llm' => $this->checkLlm(),
        ];

        return view('health.index', [
            'checks' => $checks,
            'overall' => collect($checks)->every(fn ($c) => ($c['status'] ?? '') === 'ok') ? 'ok' : 'degraded',
        ]);
    }

    /**
     * @return array{status: string, latency_ms: ?int, detail: string}
     */
    private function checkDatabase(): array
    {
        $started = microtime(true);
        try {
            DB::select('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'detail' => 'MySQL reachable',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => null,
                'detail' => 'DB error',
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms: ?int, detail: string}
     */
    private function checkFastApi(): array
    {
        $url = rtrim((string) config('services.fastapi.url'), '/').'/health';
        $started = microtime(true);
        try {
            $response = Http::timeout(5)->get($url);
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (! $response->successful()) {
                return ['status' => 'down', 'latency_ms' => $ms, 'detail' => 'HTTP '.$response->status()];
            }

            return [
                'status' => ($response->json('status') === 'ok') ? 'ok' : 'degraded',
                'latency_ms' => $ms,
                'detail' => 'llm='.($response->json('llm') ?? 'unknown'),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'latency_ms' => null, 'detail' => 'Unreachable'];
        }
    }

    /**
     * @return array{status: string, latency_ms: ?int, detail: string}
     */
    private function checkLlm(): array
    {
        $base = rtrim((string) env('LLM_BASE_URL', 'http://127.0.0.1:8080'), '/');
        $started = microtime(true);
        try {
            $response = Http::timeout(5)->get($base.'/v1/models');
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (! $response->successful()) {
                return ['status' => 'down', 'latency_ms' => $ms, 'detail' => 'HTTP '.$response->status()];
            }
            $nCtx = $response->json('data.0.meta.n_ctx');

            return [
                'status' => 'ok',
                'latency_ms' => $ms,
                'detail' => $nCtx ? ('n_ctx='.$nCtx) : 'models ok',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'latency_ms' => null, 'detail' => 'Unreachable'];
        }
    }
}
