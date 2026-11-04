<?php

namespace App\Http\Controllers\Admin;

use App\Actions\User\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignBranchStaffRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Staffing a branch from its own screen (P1-E2-T5).
 *
 * Moving an account into a branch is an update of that account, so it runs
 * through `UpdateUser` and is checked twice: `BranchPolicy::assignStaff` for the
 * destination branch and `UserPolicy::update` for the account being moved.
 *
 * Creating a brand new account stays with `UserController`; the branch screen
 * links there with the branch pre-selected.
 */
class BranchStaffController extends Controller
{
    public function store(AssignBranchStaffRequest $request, Branch $branch, UpdateUser $updateUser): RedirectResponse
    {
        $this->authorize('assignStaff', $branch);

        $staff = $request->staffToAssign();

        $this->authorize('update', $staff);

        $updateUser->handle($staff, [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'role' => $request->assignedRole()->value,
            'branch_id' => $branch->id,
            'is_active' => $staff->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$staff->name} kini ditugaskan ke cabang {$branch->name}.",
        ]);

        return back();
    }
}
