<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Package-owned token lifecycle response contract. */
#[TypeScript]
class CreatedTokenData extends BeamData
{
    public function __construct(
        /** The minted token's KEY as a string — see {@see ApiTokenData::$id} for why it is not an int. */
        public string $id,
        public string $name,
        /** The reveal-once plaintext secret — never returned again after this response. */
        public string $token,
    ) {}
}
