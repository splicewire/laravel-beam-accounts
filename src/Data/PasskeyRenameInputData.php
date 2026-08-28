<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\BeamData;

/** The passkey-rename body: the new label, and nothing else (api-surface-coherence ticket 64). */
class PasskeyRenameInputData extends BeamData
{
    public function __construct(
        #[Description('The new label for this credential. Renaming does not re-run the WebAuthn ceremony — the credential itself is untouched.')]
        public string $name,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}
