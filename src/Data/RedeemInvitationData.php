<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;

/** The redeem operation's optional, request-local Agreement members. */
#[Title('Invitation agreement member')]
final class RedeemInvitationData extends Data
{
    /** @param list<AgreementMemberData>|Optional $agreementMembers */
    public function __construct(
        #[DataCollectionOf(AgreementMemberData::class)]
        public array|Optional $agreementMembers = new Optional,
    ) {}
}
