<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The branch dashboard.
     *
     * Every query here is narrowed by the branch global scope, so a branch user
     * only ever sees their own branch (BR-3).
     */
    public function __invoke(): Response
    {
        return Inertia::render('branch/dashboard', [
            'stats' => [
                'warehouses' => Warehouse::count(),
                'staff' => User::query()->whereNotNull('branch_id')->count(),
            ],
        ]);
    }
}
