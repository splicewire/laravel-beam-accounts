<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ResetPasswordPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>|null  $body
     * @param  string|array<array-key, mixed>|object|null  $email
     */
    public function __construct(
        public string $slug,
        public ?PageEntryData $entry,
        #[ArrayItems('object')]
        public ?array $body,
        // The reset view forwards unvalidated query input; associative PHP arrays are JSON objects.
        public string|array|object|null $email,
        public ?string $token,
        public string $passwordRules,
    ) {}
}
