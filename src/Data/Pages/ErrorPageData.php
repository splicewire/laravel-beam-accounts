<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Http\ErrorPages;

/**
 * The props of the packaged `error` Inertia page (`@splicewire/beam-inertia`), rendered by
 * {@see ErrorPages} in place of the framework's bare HTML error page.
 *
 * `status` is the HTTP status the response carries — the page never changes it. `message` is the
 * exception's own text only where that text is written for the viewer (a 403's policy or `abort()`
 * message such as "This action is unauthorized.", a 503's maintenance note); a 404 or 500 always
 * carries the generic sentence, because a model-not-found or server exception message names internals.
 */
#[TypeScript]
final class ErrorPageData extends Data
{
    public function __construct(
        public int $status,
        public string $title,
        public string $message,
    ) {}
}
