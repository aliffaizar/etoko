<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The central dashboard.
     *
     * Only the counters that have data models today are shown; the remaining
     * metrics from the brief arrive with their own epics (see docs/05-roadmap.md).
     */
    public function __invoke(): Response
    {
        return Inertia::render('admin/dashboard', [
            'stats' => [
                'branches' => Branch::count(),
                'activeBranches' => Branch::active()->count(),
                'staff' => User::query()->whereNotNull('branch_id')->count(),
                'users' => User::count(),
            ],
        ]);
    }
}
