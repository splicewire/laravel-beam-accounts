<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;

/**
 * The props of the `auth/accept-invitation` Inertia page (`invitations.accept`).
 *
 * `state` is {@see InvitationRedemption}'s verdict on the link for THIS viewer — one of `ready`, `guest`,
 * `wrong-account`, `expired`, `used`, `invalid`. The team, address and role are carried only when the link
 * resolved to a live invitation: an invalid or tampered link names nothing, so a guessed token learns no
 * team name.
 *
 * `roleLabel` is the role's display label from the Role enum's catalog line (beam's ladder, localization 69b), so the
 * invitee reads the role in their language. It is null when the role string is not a {@see \Splicewire\Beam\Accounts\Enums\Role}
 * case, and the page then derives the label from `role` itself.
 *
 * `acceptUrl` is the `redeem` operation's URL (present only when the viewer can redeem); `registerUrl` /
 * `loginUrl` / `logoutUrl` are the host's Fortify routes when it mounts them, so the page never offers a
 * door the host does not have.
 */
#[TypeScript]
final class AcceptInvitationPageData extends Data
{
    public function __construct(
        public string $state,
        public ?string $teamName = null,
        public ?string $email = null,
        public ?string $role = null,
        public ?string $roleLabel = null,
        public ?string $inviterName = null,
        public ?string $expiresAt = null,
        public ?string $viewerEmail = null,
        public ?string $acceptUrl = null,
        public ?string $registerUrl = null,
        public ?string $loginUrl = null,
        public ?string $logoutUrl = null,
        #[DataCollectionOf(AgreementPromptData::class)]
        public array|Optional $agreements = new Optional,
    ) {}
}
