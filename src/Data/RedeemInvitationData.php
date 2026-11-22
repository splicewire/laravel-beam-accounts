<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;

/** The redeem operation's optional, request-local assent member. */
final class RedeemInvitationData extends Data
{
    public function __construct(public AgreementMemberData|Optional|null $agreementMember = null) {}
}
