<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AuthEntryPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>|null  $body  JsonDoc nodes, as seeded by AuthPagesSeeder.
     * @param  list<DemoAccountLinkData>|Optional  $demoAccounts
     */
    public function __construct(
        public string $slug,
        public ?PageEntryData $entry,
        #[ArrayItems('object')]
        public ?array $body,
        public bool|Optional $canResetPassword,
        // The registration-door policy (Features::enabled(Features::registration()) — the same source
        // the /register route reads), so the login Sign up affordance follows it. Optional: absent is
        // "the host did not say", and the affordance stays hidden — the same contract as canResetPassword.
        public bool|Optional $canRegister,
        public string|Optional|null $status,
        #[DataCollectionOf(DemoAccountLinkData::class)]
        public array|Optional $demoAccounts,
        public string|Optional $passwordRules,
    ) {}
}
