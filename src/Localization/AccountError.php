<?php

namespace Splicewire\Beam\Accounts\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/** Stable account refusals; declared English stays identical to the original response. */
enum AccountError: string implements CodedError
{
    use IsCodedError;

    #[Message('This passkey challenge has expired. Please try again.')]
    case PasskeyExpired = 'passkey_challenge_expired';

    #[Message('Passkey verification failed.')]
    case PasskeyFailed = 'passkey_verification_failed';

    #[Message('Unauthorized.')]
    case Unauthorized = 'unauthorized';

    #[Message('Invitation not found')]
    case InvitationNotFound = 'invitation_not_found';

    #[Message('Token not found')]
    case TokenNotFound = 'token_not_found';

    #[Message('Cannot renew an archived token.')]
    case RenewArchived = 'token_renew_archived';

    #[Message('You cannot rotate the token you are using.')]
    case RotateCurrent = 'token_rotate_current';

    #[Message('Cannot rotate an archived token.')]
    case RotateArchived = 'token_rotate_archived';

    #[Message('You cannot archive the token you are using.')]
    case ArchiveCurrent = 'token_archive_current';

    #[Message('Token already archived')]
    case AlreadyArchived = 'token_already_archived';

    #[Message('You cannot delete the token you are using.')]
    case DeleteCurrent = 'token_delete_current';

    #[Message('Archive the token before deleting it.')]
    case DeleteActive = 'token_delete_active';

    #[Message('Member not found')]
    case MemberNotFound = 'member_not_found';

    #[Message('A team must keep at least one owner.')]
    case LastOwner = 'last_owner';

    #[Message('Demo login is disabled in this environment.')]
    case DemoDisabled = 'demo_disabled';

    #[Message('Not authenticated.')]
    case NotAuthenticated = 'not_authenticated';

    #[Message('You cannot impersonate yourself.')]
    case ImpersonateSelf = 'impersonate_self';

    #[Message('This account cannot be impersonated.')]
    case ImpersonateForbidden = 'impersonate_forbidden';

    #[Message('No active team.')]
    case NoActiveTeam = 'no_active_team';

    #[Message('Only a team owner can manage members.')]
    case TeamOwnerRequired = 'team_owner_required';

    #[Message('You are not a member of this team.')]
    case NotTeamMember = 'not_team_member';

    #[Message('No active team to invite into.')]
    case NoInvitationTeam = 'no_invitation_team';

    #[Message('Only owners and admins can manage invitations.')]
    case InvitationManagerRequired = 'invitation_manager_required';

    #[Message('Not impersonating.')]
    case NotImpersonating = 'not_impersonating';

    #[Message('This invitation has already been used.')]
    case InvitationUsed = 'invitation_used';

    #[Message('This invitation has expired. Ask the team for a new one.')]
    case InvitationExpired = 'invitation_expired';

    #[Message('This invitation was sent to a different email address.')]
    case InvitationWrongAccount = 'invitation_wrong_account';

    #[Message('Sign in to accept this invitation.')]
    case InvitationGuest = 'invitation_guest';

    #[Message('This invitation link is not valid.')]
    case InvitationInvalid = 'invitation_invalid';

    #[Message('Unknown demo subject [:subject]. Expected one of: :expected')]
    case DemoUnknown = 'demo_unknown';

    #[Message(':email is already a member of this team.')]
    case AlreadyMember = 'already_member';

    #[Message('A token can only be scoped to permissions you hold. Not held: :permissions.')]
    case TokenAbilities = 'token_abilities';

    // The machine-token issuer's refusals (ADR 0006, credential-scope 03). Each names the kind it refused.

    #[Message('A token made with a scoped token can only carry what that token carries. Not carried: :abilities.')]
    case TokenAbilitiesBeyondActingToken = 'token_abilities_beyond_acting_token';

    #[Message('No token kind named :kind is declared.')]
    case TokenKindUndeclared = 'token_kind_undeclared';

    #[Message('Tokens cannot be issued while credential scope enforcement is off.')]
    case TokenIssuerEnforcementOff = 'token_issuer_enforcement_off';

    #[Message('A :kind token needs an explicit ability set and can never carry *.')]
    case TokenWildcardRefused = 'token_wildcard_refused';

    #[Message('A :kind token carries the abilities its kind declares; a request cannot choose them.')]
    case TokenKindDeclaresAbilities = 'token_kind_declares_abilities';

    #[Message('Issuing a :kind token needs a signed-in principal.')]
    case TokenActorRequired = 'token_actor_required';

    #[Message('Issuing a :kind token needs a credential that carries :ability.')]
    case TokenIssuanceAbilityMissing = 'token_issuance_ability_missing';

    #[Message('The principal for a :kind token could not be resolved.')]
    case TokenTargetUnresolved = 'token_target_unresolved';

    #[Message('A :kind token is issued only for the principal and tenant its kind resolves.')]
    case TokenTargetMismatch = 'token_target_mismatch';

    #[Message('A :kind token is for another principal; your own token is a personal access token.')]
    case TokenTargetIsActor = 'token_target_is_actor';

    #[Message('You do not have authority to issue a :kind token for that principal.')]
    case TokenTargetNoAuthority = 'token_target_no_authority';

    #[Message('A :kind token cannot be rotated; issue it again.')]
    case TokenKindNotRotatable = 'token_kind_not_rotatable';

    #[Message('A :kind token is not issued for an operation; its kind resolves its own subject.')]
    case TokenKindNotOperationBound = 'token_kind_not_operation_bound';

    #[Message('The :role role cannot be assigned by invitation.')]
    case RoleNotInvitable = 'role_not_invitable';

    #[Message('The team owner cannot be removed.')]
    case RemoveOwner = 'remove_owner';

    #[Message('Only a team owner may manage members.')]
    case TeamManageRequired = 'team_manage_required';

    #[Message('The Audience area reads each index as its viewer; with no signed-in viewer it cannot be read.')]
    case AudienceViewerRequired = 'audience_viewer_required';

    #[Message('We can\'t find a user with that email address.')]
    case ResetInvalidUser = 'reset_invalid_user';

    #[Message('This password reset token is invalid.')]
    case ResetInvalidToken = 'reset_invalid_token';

    #[Message('Please wait before retrying.')]
    case ResetThrottled = 'reset_throttled';

    #[Message('Authentication required.')]
    case AuthenticationRequired = 'authentication_required';

    #[Message('Reading [:resource] requires membership in the current team.')]
    case ResourceMembershipRequired = 'resource_membership_required';

    #[Message('config(beam.accounts.teams.resolver) returned a :type, which does not implement TeamContract.')]
    case TeamResolverInvalid = 'team_resolver_invalid';

    public function abortIf(bool $condition, int $status, array $params = []): void
    {
        if ($condition) {
            $this->abort($status, $params);
        }
    }

    public function abortUnless(bool $condition, int $status, array $params = []): void
    {
        $this->abortIf(! $condition, $status, $params);
    }

    /** Keep each existing HTTP status while adding its language-independent identity. */
    public function abort(int $status, array $params = []): never
    {
        throw $status === 404
            ? new AccountNotFoundHttpException($this, $params)
            : new AccountHttpException($status, $this, $params);
    }
}
