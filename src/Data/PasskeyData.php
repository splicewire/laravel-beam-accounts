<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyController;

/**
 * A passkey credential row as {@see PasskeyController}
 * presents it — the `present()` map ({id, name, last_used_at, created_at}), inside the ResponseBody
 * `data` slot: a list on `passkeys.index`, a single row on `passkeys.store`/`passkeys.update`. Type-only
 * projection contract (the wire stays the controller's hand-built array); snake_case props mirror the
 * wire verbatim. Timestamps are ISO-8601 strings, nullable (a credential that has never been used, or a
 * row without timestamps).
 *
 * `#[TypeScript]`-emitted so the host can declare it via `->returns()` and codegen derives the
 * passkeys hooks' types (sdk.returns-coverage).
 */
#[TypeScript]
class PasskeyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $last_used_at,
        public ?string $created_at,
    ) {}
}
