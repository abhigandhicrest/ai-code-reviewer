<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->is_admin, 403);

        return view('audit.index', [
            'logs' => AuditLog::query()->with('user')->latest()->paginate(50),
        ]);
    }
}
