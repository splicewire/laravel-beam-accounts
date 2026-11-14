<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProfilePageData extends Data
{
    public function __construct(
        public ?PageEntryData $entry,
        public bool $mustVerifyEmail,
        public ?string $status,
        // localization 36 (additive): the person's saved language (null = automatic) and the languages the picker
        // offers; labels are the client's Intl.DisplayNames.
        public ?string $preferredLocale = null,
        /** @var list<string> */
        public array $supportedLocales = [],
    ) {}
}
