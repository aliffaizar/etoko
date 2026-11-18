<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use App\Support\AdminHost;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Every post-authentication response is replaced, because each of Fortify's
     * defaults lands on `fortify.home` and would bypass the role-based redirect
     * (P1-E1-T7, ADR-015).
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Refuse credentials belonging to a deactivated account (P1-E1-T3).
     *
     * Returning null makes Fortify report the generic failure message, so a
     * deactivated account is not distinguishable from a wrong password.
     *
     * The passkey flow never reaches this callback — it verifies a credential
     * instead of a password — so it carries its own check. Without it, a
     * deactivated account that had registered a passkey could still sign in.
     * The package refuses with its own generic message, which again keeps the
     * reason from leaking, and the refusal happens before the session is
     * created so no login is recorded.
     *
     * The owner is nullable because an account can be soft deleted while its
     * passkeys stay behind; a passkey without a live owner is refused too.
     *
     * Both paths also refuse a customer on the panel host (P2-E9-T7).
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where(Fortify::username(), $request->input(Fortify::username()))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            return self::maySignInHere($request, $user) ? $user : null;
        });

        Passkeys::authorizeLoginUsing(function (Request $request, ?PasskeyUser $user): bool {
            return $user instanceof User && self::maySignInHere($request, $user);
        });

        $this->configurePasswordResetLinks();
    }

    /**
     * Whether the account may open a session on the host the request came to.
     *
     * A deactivated account may not sign in anywhere. On the panel host only
     * staff may (ADR-063): the customer is refused with the same generic
     * failure as a wrong password, so the panel host does not confirm that a
     * shopper's address has an account. The two-factor challenge needs no
     * check of its own — Fortify asks this callback for the password before it
     * issues the challenge.
     */
    private static function maySignInHere(Request $request, User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return ! AdminHost::serves($request) || $user->isStaff();
    }

    /**
     * Send each account's reset link to the host it signs in on (ADR-063).
     *
     * Left alone, the link names whichever host the form was sent from. Once
     * the panels have a host of their own, staff are sent to it and everyone
     * else to the storefront, so a customer who asked on the panel host is not
     * given a link to a host that will refuse them.
     */
    private function configurePasswordResetLinks(): void
    {
        if (! AdminHost::isSeparate()) {
            return;
        }

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $path = route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], absolute: false);

            $root = $user->isStaff() ? AdminHost::rootUrl() : config('app.url');

            return rtrim((string) $root, '/').$path;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            // Sign-up is refused on the panel host (RefuseRegistrationOnAdminHost).
            'canRegister' => Features::enabled(Features::registration()) && ! AdminHost::serves($request),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
