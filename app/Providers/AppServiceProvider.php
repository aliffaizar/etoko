<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Branch\BranchContext;
use App\Support\ErrorPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BranchContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureErrorPages();
    }

    /**
     * Render refusals and failures as an Inertia page (BUG-04).
     *
     * A 403 is a normal outcome of the three guard layers in docs/04-rbac.md,
     * so it has to read as a rule of the application and not as a broken one.
     * `withSharedData()` runs the Inertia middleware for the response, because
     * these are raised outside it and would otherwise reach the page without
     * `auth` and lose the panel's own chrome.
     *
     * A failing 500 keeps Laravel's own page whenever debugging is on, so the
     * stack trace stays reachable while developing.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            $status = $response->statusCode();

            if (! in_array($status, ErrorPage::HANDLED_STATUSES, strict: true)) {
                return null;
            }

            if (config('app.debug') === true && in_array($status, [500, 503], strict: true)) {
                return null;
            }

            return $response
                ->render('error', ErrorPage::propsFor($status, $response->exception, $response->request))
                ->withSharedData();
        });
    }

    /**
     * Grant the Super Admin role every ability without listing them.
     *
     * Returning null for everyone else lets the normal policy and permission
     * checks run.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasAppRole(UserRole::SuperAdmin) ? true : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
