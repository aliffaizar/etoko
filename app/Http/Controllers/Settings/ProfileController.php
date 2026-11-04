<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profil berhasil diperbarui.']);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's own account.
     *
     * Panel accounts are refused outright. They are opened and closed by
     * someone else at `/admin/users`, where `UserController::destroy()` already
     * refuses to let anyone delete their own account, and the Super Admin role
     * is the only holder of `roles.manage` (config/rbac.php): a Super Admin who
     * deleted themselves would leave nobody able to hand the role to anyone
     * else, and the soft-deleted row would keep their email address (ADR-010).
     * A shopper deletes their own account as before.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(
            $user->isStaff(),
            403,
            'Akun panel tidak dapat dihapus dari halaman ini. Hubungi Super Admin atau Admin Pusat untuk menutup akun Anda.',
        );

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
