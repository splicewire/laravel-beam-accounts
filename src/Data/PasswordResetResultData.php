<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasswordResetController;

/**
 * Outcome of a password-recovery verb ({@see PasswordResetController}) — the machine-readable
 * result carried in the standard envelope's `data` slot ('link-sent' for the enumeration-safe
 * forgot-password acknowledgment, 'reset' for a confirmed reset), while the human copy rides the
 * envelope's `message` slot. A Data class (not a bare `{message}` body) so the route's
 * `->returns()` declaration is honest — beam-rank's `RankRemovedData` small-result idiom.
 *
 * Deliberately does NOT echo the submitted email: the forgot-password response must stay
 * byte-identical for existing and missing accounts (no user enumeration), and an echoed input
 * would break that byte-level guarantee the feature test pins.
 */
#[TypeScript]
class PasswordResetResultData extends Data
{
    public function __construct(
        public string $status,
    ) {}
}
