<?php

namespace App\Http\Controllers;

use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UsageController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = (bool) $user->is_admin;

        $baseQuery = UsageLog::query()->with('user');
        if (! $isAdmin) {
            $baseQuery->where('user_id', $user->id);
        }

        $statsQuery = clone $baseQuery;
        $totalReviews = (clone $statsQuery)->count();
        $successReviews = (clone $statsQuery)->where('success', true)->count();
        $failedReviews = (clone $statsQuery)->where('success', false)->count();
        $avgLatency = (int) round((clone $statsQuery)->whereNotNull('latency_ms')->avg('latency_ms') ?? 0);
        $totalTokens = (int) ((clone $statsQuery)->sum('estimated_tokens') ?? 0);

        $byLanguage = (clone $baseQuery)
            ->select('language', DB::raw('COUNT(*) as total'))
            ->groupBy('language')
            ->orderByDesc('total')
            ->get();

        $byDay = (clone $baseQuery)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderByDesc('day')
            ->limit(14)
            ->get();

        $recent = (clone $baseQuery)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $usersCount = $isAdmin ? User::count() : null;

        return view('usage.index', [
            'isAdmin' => $isAdmin,
            'totalReviews' => $totalReviews,
            'successReviews' => $successReviews,
            'failedReviews' => $failedReviews,
            'avgLatency' => $avgLatency,
            'totalTokens' => $totalTokens,
            'byLanguage' => $byLanguage,
            'byDay' => $byDay,
            'recent' => $recent,
            'usersCount' => $usersCount,
        ]);
    }
}
