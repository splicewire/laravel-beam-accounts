<?php

namespace Splicewire\Beam\Accounts\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Data\AccountErrorData;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** JSON-only; the normal exception handler and branded browser page remain in charge of HTML. */
final class AccountErrorResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        // Laravel converts an AuthorizationException into an AccessDeniedHttpException before callbacks run.
        $source = $exception instanceof AccountHttpException || $exception instanceof AccountAuthorizationException
            ? $exception
            : $exception->getPrevious();
        $response = $source instanceof \Illuminate\Auth\Access\AuthorizationException ? $source->response() : null;
        $codedPolicy = $response instanceof AccountAuthorizationResponse ? $response->error : null;
        if (! $source instanceof AccountHttpException && ! $source instanceof AccountAuthorizationException && $codedPolicy === null) {
            return null;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 403;

        return new JsonResponse((new AccountErrorData($source->getMessage(), ($codedPolicy ?? $source->error)->code()))->toArray(), $status);
    }
}
