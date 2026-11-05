<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Data\BeamData;

/**
 * One result of the local-only "log in as any user" picker (S1): who the user is, and the signed, expiring
 * login-as link that becomes them. Minted only by {@see DemoLoginLinks::users()}, which carries the gate.
 *
 * `email` is carried because it is what tells two same-named users apart in a typeahead, and because a token
 * host (the flagship SPA) converts a choice into its own hop link by email rather than following `url`.
 */
#[TypeScript]
final class DemoUserLinkData extends BeamData
{
    public function __construct(
        #[Description('The user\'s key.')]
        public string $id,
        #[Description('The user\'s display name.')]
        public string $name,
        #[Description('The user\'s email address.')]
        public string $email,
        #[Description('A signed, expiring login-as link for this user. Following it signs the browser in as them.')]
        public string $url,
    ) {}
}
