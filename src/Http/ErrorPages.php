<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Splicewire\Beam\Accounts\Data\Pages\ErrorPageData;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Branded error pages: a 403 / 404 / 419 / 500 / 503 that a BROWSER receives renders the packaged
 * `error` Inertia page (`@splicewire/beam-inertia`) inside the app's own chrome — the account shell for
 * a signed-in viewer, the site shell for a guest — instead of the framework's bare HTML page.
 *
 * A host wires it in `bootstrap/app.php`:
 *
 *     ->withExceptions(function (Exceptions $exceptions): void {
 *         $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
 *         ErrorPages::register($exceptions);
 *     })
 *
 * ## What it leaves alone
 *
 *  - **JSON.** A response the handler already rendered as JSON (the host's `shouldRenderJsonWhen`, or
 *    any request that `expectsJson()`) is returned untouched, so an API client's error body is exactly
 *    what it was.
 *  - **The status.** The page response carries the original status code; only the body changes.
 *  - **A response somebody chose.** An `HttpResponseException`, a `Responsable` exception, or one with
 *    its own `render()` produced a deliberate response; it is not replaced.
 *  - **The debug page.** A 500 while `app.debug` is on keeps the framework's exception page, which is
 *    the one a developer needs. A 503 is always branded (maintenance is not a debugging aid).
 *  - **Its own failure.** If the page cannot render (no root view, no Inertia), the original response
 *    is returned rather than a second error.
 *
 * The response goes through `Illuminate\Foundation\Exceptions\Handler::respondUsing()`, which holds ONE
 * callback: a host that already calls `$exceptions->respond()` should call {@see render()} from it.
 */
final class ErrorPages
{
    /** The Inertia page name `@splicewire/beam-inertia` registers. */
    public const COMPONENT = 'error';

    /** @var list<int> */
    public const STATUSES = [403, 404, 419, 500, 503];

    /**
     * @param  list<int>  $statuses
     */
    public static function register(Exceptions $exceptions, array $statuses = self::STATUSES): void
    {
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request): Response => self::render($response, $e, $request, $statuses),
        );
    }

    /**
     * @param  list<int>  $statuses
     */
    public static function render(Response $response, Throwable $e, Request $request, array $statuses = self::STATUSES): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, $statuses, true)
            || $response instanceof JsonResponse
            || $response instanceof RedirectResponse
            || $request->expectsJson()
            || $e instanceof HttpResponseException
            || $e instanceof Responsable
            || method_exists($e, 'render')
            || ($status === 500 && (bool) config('app.debug'))) {
            return $response;
        }

        try {
            return Inertia::render(self::COMPONENT, self::page($status, $e))
                ->toResponse($request)
                ->setStatusCode($status);
        } catch (Throwable) {
            return $response;
        }
    }

    public static function page(int $status, ?Throwable $e = null): ErrorPageData
    {
        // Only a 403 or 503 message is written for the viewer (a policy's "This action is
        // unauthorized.", an `abort(403, '…')`, a maintenance note). A 404's message can name a model
        // class and a 500's anything at all, so those two never pass theirs through.
        $own = $e instanceof HttpExceptionInterface && in_array($status, [403, 503], true)
            ? trim($e->getMessage())
            : '';

        [$title, $fallback] = match ($status) {
            403 => ['You don’t have access to this page', 'You don’t have permission to open this page.'],
            404 => ['Page not found', 'The page you were looking for doesn’t exist or has moved.'],
            419 => ['This page expired', 'Your session timed out. Refresh the page and try again.'],
            503 => ['Down for maintenance', 'We’re making some changes. Please check back shortly.'],
            default => ['Something went wrong', 'An unexpected error stopped this page from loading. Please try again in a moment.'],
        };

        return new ErrorPageData(status: $status, title: $title, message: $own !== '' ? $own : $fallback);
    }
}
