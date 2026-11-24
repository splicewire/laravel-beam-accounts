<?php

use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Write\WriteRequirement;

beforeEach(function (): void {
    $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    $this->agreementResolver = new Agreements14InvitationResolver;
    app()->instance(RequirementAdmission::class, new RequirementAdmission($this->agreementResolver));
    app()->instance(RequirementDeclarations::class, new Agreements14InvitationDeclarations);
    $this->assentRecorder = new Agreements14InvitationRecorder;
    app()->instance(AssentRecorder::class, $this->assentRecorder);
});

function agreements14Invitation(User $owner, Team $team, string $email): Invitation
{
    return Invitation::create([
        'team_id' => (string) $team->getKey(),
        'email' => $email,
        'role' => Role::Member->value,
        'token' => bin2hex(random_bytes(24)),
        'invited_by' => $owner->getKey(),
    ]);
}

function agreements14TeamAndInvitee(string $email): array
{
    $owner = User::create(['name' => 'Olive Owner', 'email' => 'owner@example.test', 'password' => 'password-1234']);
    $team = app(TeamProvisioner::class)->createTeamFor($owner, 'Acme');
    $invitee = User::create(['name' => 'Ivy Invitee', 'email' => $email, 'password' => 'password-1234']);

    return [$owner, $team, $invitee, agreements14Invitation($owner, $team, $email)];
}

it('refuses invitation redemption without this request member and leaves membership and invitation untouched', function (): void {
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');

    $this->actingAs($invitee)
        ->postJson("/invitations/{$invitation->token}/redeem")
        ->assertStatus(428)
        ->assertJsonPath('requirements.0.key', 'terms');

    expect(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('refuses an invitation member from an earlier version and leaves membership and invitation untouched', function (): void {
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');

    $this->actingAs($invitee)
        ->postJson("/invitations/{$invitation->token}/redeem", [
            'agreementMembers' => [['key' => 'terms', 'version' => 2, 'digest' => 'old-digest']],
        ])
        ->assertStatus(428)
        ->assertJsonPath('requirements.0.key', 'terms');

    expect(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('records the invitee and the resulting membership exactly once after admitted redemption', function (): void {
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');

    $this->actingAs($invitee)
        ->postJson("/invitations/{$invitation->token}/redeem", [
            'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
        ])
        ->assertOk();

    $membership = Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->sole();

    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($this->assentRecorder->calls)->toHaveCount(1)
        ->and($this->assentRecorder->calls[0][0]->is($membership))->toBeTrue()
        ->and($this->assentRecorder->calls[0][1]->behavior)->toBe('invitations.redeem')
        ->and($this->assentRecorder->calls[0][1]->actor->is($invitee))->toBeTrue()
        ->and($this->assentRecorder->calls[0][1]->submittedMembers['terms']->key)->toBe('terms');
});

it('admits an invitation only when every blocking agreement has its own submitted member', function (): void {
    $requirements = [new Requires('terms'), new Requires('privacy')];
    app()->instance(RequirementDeclarations::class, new Agreements14InvitationDeclarations($requirements));
    app()->instance(RequirementAdmission::class, new RequirementAdmission(new Agreements14InvitationResolver($requirements)));
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');

    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem", [
        'agreementMembers' => [
            ['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest'],
            ['key' => 'privacy', 'version' => 4, 'digest' => 'privacy-digest'],
        ],
    ])->assertOk();

    expect(array_keys($this->assentRecorder->calls[0][1]->submittedMembers))->toBe(['terms', 'privacy'])
        ->and(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeTrue();
});

it('names only the missing invitation agreement in its 428 refusal', function (): void {
    $requirements = [new Requires('terms'), new Requires('privacy')];
    app()->instance(RequirementDeclarations::class, new Agreements14InvitationDeclarations($requirements));
    app()->instance(RequirementAdmission::class, new RequirementAdmission(new Agreements14InvitationResolver($requirements)));
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');

    $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem", [
        'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
    ])->assertStatus(428)->assertJsonPath('requirements.0.key', 'privacy');

    expect(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeFalse()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('rolls back membership and accepted_at when the assent append fails', function (): void {
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');
    $this->assentRecorder->fail = true;
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem", [
        'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
    ]))->toThrow(RuntimeException::class, 'assent append failed');

    expect(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('keeps invitation redemption available when the optional agreements seam is not installed', function (): void {
    [, $team, $invitee, $invitation] = agreements14TeamAndInvitee('ivy@example.test');
    app()->forgetInstance(RequirementDeclarations::class);
    app()->forgetInstance(RequirementAdmission::class);
    app()->forgetInstance(AssentRecorder::class);

    $this->actingAs($invitee)
        ->postJson("/invitations/{$invitation->token}/redeem")
        ->assertOk();

    expect(Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->exists())->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

final class Agreements14InvitationResolver implements RequirementResolver
{
    /** @param list<Requires> $requirements */
    public function __construct(private array $requirements = [new Requires('terms')]) {}

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        if ($behavior !== 'invitations.redeem') {
            return [];
        }

        $outstanding = [];
        foreach ($this->requirements as $requirement) {
            $member = $submittedMembers[$requirement->agreement] ?? null;
            $valid = $member instanceof AgreementMemberData
                && $member->key === $requirement->agreement
                && (($requirement->agreement === 'terms' && $member->version === 3 && $member->digest === 'frozen-digest')
                    || ($requirement->agreement === 'privacy' && $member->version === 4 && $member->digest === 'privacy-digest'));
            if (! $valid) {
                $outstanding[] = new OutstandingRequirementData(
                    key: $requirement->agreement,
                    version: $requirement->agreement === 'terms' ? 3 : 4,
                    digest: $requirement->agreement === 'terms' ? 'frozen-digest' : 'privacy-digest',
                    kind: 'contract',
                    title: ucfirst($requirement->agreement),
                    scope: 'party',
                    subject: null,
                    readUrl: '/agreements/'.$requirement->agreement,
                    acceptOperation: null,
                    member: 'agreementMembers',
                );
            }
        }

        return $outstanding;
    }
}

final class Agreements14InvitationDeclarations implements RequirementDeclarations
{
    /** @param list<Requires> $requirements */
    public function __construct(private array $requirements = [new Requires('terms')]) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $behavior === 'invitations.redeem' ? $this->requirements : [];
    }

    public function all(): array
    {
        return $this->requirements === [] ? [] : ['invitations.redeem' => $this->requirements];
    }
}

final class Agreements14InvitationRecorder implements AssentRecorder
{
    /** @var list<array{\Illuminate\Database\Eloquent\Model, WriteRequirement}> */
    public array $calls = [];

    public bool $fail = false;

    public function record(Illuminate\Database\Eloquent\Model $subject, WriteRequirement $requirement): void
    {
        $this->calls[] = [$subject, $requirement];

        if ($this->fail) {
            throw new RuntimeException('assent append failed');
        }
    }
}
