<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * One `{value,label}` role option as {@see Role::options()} derives it — the select/editor pair
 * the team UI renders. Lives here (not in the tenancy package serving the endpoint) because
 * beam-accounts' {@see Role} enum is the single source of truth for the role vocabulary
 * (FC-11/FC-12); this is that vocabulary's typed wire shape, never a hand-authored list.
 */
#[TypeScript]
class RoleOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
