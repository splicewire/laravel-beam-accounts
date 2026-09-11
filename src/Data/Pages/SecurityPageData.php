<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SecurityPageData extends Data
{
    /** @param list<SecurityPasskeyData> $passkeys */
    public function __construct(
        public bool $canManageTwoFactor,
        public bool $canManagePasskeys,
        #[DataCollectionOf(SecurityPasskeyData::class)]
        public array $passkeys,
        public string $passwordRules,
        public bool|Optional $twoFactorEnabled,
        public bool|Optional $requiresConfirmation,
    ) {}
}
