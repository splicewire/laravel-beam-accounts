<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyController;

/**
 * A passkey credential row as {@see PasskeyController}
 * presents it — the `present()` map ({id, name, lastUsedAt, createdAt}), inside the ResponseBody
 * `data` slot: a list on `passkeys.index`, a single row on `passkeys.store`/`passkeys.update`, and the
 * removed credential's final-state snapshot on `passkeys.destroy` (the destroy-returns-the-resource
 * envelope rule). Type-only
 * projection contract (the wire stays the controller's hand-built array). Timestamps are ISO-8601 strings,
 * nullable (a credential that has never been used, or a row without timestamps).
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
        #[MapName('lastUsedAt')]
        public ?string $lastUsedAt,
        #[MapName('createdAt')]
        public ?string $createdAt,
    ) {}
}
