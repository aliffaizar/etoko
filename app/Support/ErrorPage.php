<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The props of the Inertia error page (BUG-04).
 *
 * Without this the panel showed Laravel's own error page: English, unstyled and
 * with no way back — and for an action fired with `router.delete()` it arrived
 * as a modal with the raw HTML in an iframe, because the response was not an
 * Inertia response at all. Everything here is Indonesian, and the way back is
 * the user's own landing page rather than `/`.
 */
final class ErrorPage
{
    /**
     * Statuses rendered as a page of ours rather than by Laravel.
     *
     * @var array<int, int>
     */
    public const HANDLED_STATUSES = [403, 404, 419, 500, 503];

    /**
     * Statuses whose attached message is shown to the user.
     *
     * Only 403: a refusal is where this application writes its own reason
     * ("Cabang ini masih memiliki 3 akun staf…"). The message on a 404 or a 500
     * comes from the framework and is either English boilerplate ("The route x
     * could not be found.") or an internal detail ("No query results for model
     * [App\Models\Warehouse]") that should not be on screen at all.
     *
     * @var array<int, int>
     */
    private const STATUSES_WITH_MESSAGE = [403];

    /**
     * The messages the framework attaches to a 403 itself, which are English
     * and say nothing a user can act on.
     *
     * @var array<int, string>
     */
    private const GENERIC_MESSAGES = [
        'This action is unauthorized.',
        'Unauthenticated.',
        'Forbidden',
    ];

    /**
     * @return array{status: int, title: string, description: string, message: string|null, homeUrl: string, homeLabel: string}
     */
    public static function propsFor(int $status, Throwable $exception, Request $request): array
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        return [
            'status' => $status,
            'title' => self::title($status),
            'description' => self::description($status),
            'message' => self::message($status, $exception),
            // A guest goes to the storefront; `RoleHome` would hand them the
            // authenticated dashboard, which only bounces them to the login
            // screen again. On the panel host, which has no storefront
            // (ADR-063), the guest is sent to sign in there instead.
            'homeUrl' => match (true) {
                $user !== null => RoleHome::urlFor($user, $request),
                AdminHost::serves($request) => route('login'),
                default => route('home'),
            },
            'homeLabel' => $user === null ? 'Kembali ke halaman utama' : 'Kembali ke halaman Anda',
        ];
    }

    private static function title(int $status): string
    {
        return match ($status) {
            403 => 'Akses ditolak',
            404 => 'Halaman tidak ditemukan',
            419 => 'Sesi Anda berakhir',
            503 => 'Sedang dalam pemeliharaan',
            default => 'Terjadi kesalahan',
        };
    }

    private static function description(int $status): string
    {
        return match ($status) {
            403 => 'Anda tidak memiliki izin untuk membuka halaman ini atau melakukan tindakan tersebut.',
            404 => 'Halaman yang Anda cari tidak ada, atau sudah dipindahkan.',
            419 => 'Halaman terbuka terlalu lama. Muat ulang halaman, lalu coba lagi.',
            503 => 'Aplikasi sedang dalam pemeliharaan. Silakan coba beberapa saat lagi.',
            default => 'Terjadi kesalahan di sisi server. Tim kami sudah mencatatnya.',
        };
    }

    /**
     * The message the server deliberately attached, if any.
     */
    private static function message(int $status, Throwable $exception): ?string
    {
        if (! in_array($status, self::STATUSES_WITH_MESSAGE, strict: true)) {
            return null;
        }

        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $message = trim($exception->getMessage());

        if ($message === '' || in_array($message, self::GENERIC_MESSAGES, strict: true)) {
            return null;
        }

        return $message;
    }
}
