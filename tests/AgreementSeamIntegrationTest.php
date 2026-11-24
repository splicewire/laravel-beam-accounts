<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Rushing\Agreements\Ledger;
use Rushing\Agreements\Models\LedgerEntry;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Agreements\AccountAssentRecorder;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Agreements\BeamAgreementsServiceProvider;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Organisation\ActingOrganisation;
use Splicewire\Beam\Agreements\Registry\AgreementDefinition;
use Splicewire\Beam\Agreements\Registry\AgreementRegistry;
use Splicewire\Beam\Agreements\Registry\RecordSource;
use Splicewire\Beam\Agreements\Requirements\AgreementLedger;
use Splicewire\Beam\Agreements\Requirements\AttachedRequirement;
use Splicewire\Beam\Agreements\Requirements\RequirementRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/** Accounts' write paths against the real seam provider and its ledger, not a recorder double. */
final class AgreementSeamIntegrationTest extends TestCase
{
    private AgreementMemberData $member;

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        array_splice($providers, 1, 0, [BeamAgreementsServiceProvider::class]);

        return $providers;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migrations = __DIR__.'/../vendor/splicewire/laravel-beam-agreements/database/migrations/shared/';
        (require $migrations.'create_agreement_ledger_tables.php.stub')->up();
        (require $migrations.'add_summary_to_agreement_versions.php.stub')->up();
        (require $migrations.'create_agreement_consent_requests_table.php.stub')->up();

        app(AgreementRegistry::class)->register(new AgreementDefinition(
            key: 'terms',
            kind: 'contract',
            title: 'Terms',
            source: new RecordSource(static fn (): string => 'Frozen terms text.'),
            organisationAbility: 'agreements.accept-for-team',
        ));
        $version = app(Ledger::class)->freeze(app(AgreementRegistry::class)->get('terms'), 'v1', now()->subDay(), true);
        $this->member = new AgreementMemberData('terms', 1, $version->digest);
        app(RequirementRegistry::class)->attach('auth.register', 'terms', AttachedRequirement::PARTY);
        app(RequirementRegistry::class)->attach('invitations.redeem', 'terms', AttachedRequirement::PARTY);
    }

    public function test_signup_uses_the_real_seam_and_records_once_against_the_new_party(): void
    {
        self::assertInstanceOf(\Splicewire\Beam\Agreements\Recording\AgreementAssentRecorder::class, app(AssentRecorder::class));
        self::assertInstanceOf(\Splicewire\Beam\Agreements\Recording\AgreementAccountAssentRecorder::class, app(AccountAssentRecorder::class));

        $user = app(CreateNewUser::class)->create([
            'name' => 'Ada',
            'email' => 'ada-seam@example.test',
            'password' => 'password-1234',
            'passwordConfirmation' => 'password-1234',
            'agreementMembers' => [['key' => 'terms', 'version' => $this->member->version, 'digest' => $this->member->digest]],
        ]);

        $entry = LedgerEntry::query()->sole();
        self::assertSame((string) $user->getKey(), $entry->party_id);
        self::assertNull($entry->subject_id);
        self::assertSame('auth.register', $entry->behavior);
        self::assertSame('form', $entry->method);

        app(AccountAssentRecorder::class)->record([$this->member], $user, $user, null, 'auth.register');
        self::assertSame(1, LedgerEntry::query()->count(), 'an existing party member must not be recorded twice');
    }

    public function test_signup_writes_to_the_resolved_central_ledger_in_tenant_context(): void
    {
        $this->separateCentralLedger();

        $user = app(CreateNewUser::class)->create([
            'name' => 'Ada',
            'email' => 'ada-central-seam@example.test',
            'password' => 'password-1234',
            'passwordConfirmation' => 'password-1234',
            'agreementMembers' => [['key' => 'terms', 'version' => $this->member->version, 'digest' => $this->member->digest]],
        ]);

        self::assertSame('central', AgreementLedger::connectionName());
        self::assertSame((string) $user->getKey(), LedgerEntry::on('central')->sole()->party_id);
        self::assertSame(0, LedgerEntry::query()->count(), 'the tenant/default connection must not receive the ledger entry');
    }

    public function test_signup_does_not_record_a_notice_member_as_assent(): void
    {
        app(AgreementRegistry::class)->register(new AgreementDefinition(
            key: 'privacy-notice',
            kind: 'notice',
            title: 'Privacy notice',
            source: new RecordSource(static fn (): string => 'Privacy notice text.'),
        ));
        $noticeVersion = app(Ledger::class)->freeze(app(AgreementRegistry::class)->get('privacy-notice'), 'v1', now()->subDay(), true);
        app(RequirementRegistry::class)->attach('auth.register', 'privacy-notice', AttachedRequirement::PARTY);

        app(CreateNewUser::class)->create([
            'name' => 'Ada',
            'email' => 'ada-notice-seam@example.test',
            'password' => 'password-1234',
            'passwordConfirmation' => 'password-1234',
            'agreementMembers' => [
                ['key' => 'terms', 'version' => $this->member->version, 'digest' => $this->member->digest],
                ['key' => 'privacy-notice', 'version' => 1, 'digest' => $noticeVersion->digest],
            ],
        ]);

        self::assertSame(1, LedgerEntry::query()->count());
        self::assertSame('terms', LedgerEntry::query()->sole()->agreement_key);
    }

    public function test_invitation_records_the_real_party_entry_when_organisation_assent_is_authorised(): void
    {
        $owner = $this->user('Olive Owner', 'owner@example.test');
        $team = app(TeamProvisioner::class)->createTeamFor($owner, 'Acme');
        $invitee = $this->user('Ivy Invitee', 'ivy@example.test');
        $invitation = $this->invitation($owner, $team, $invitee->email);

        app(RequirementRegistry::class)->attach('invitations.redeem', 'terms', AttachedRequirement::ORGANISATION);
        app()->instance(ActingOrganisation::class, new TestActingOrganisation($team));
        Gate::define('agreements.accept-for-team', static fn ($actor, $organisation): bool => $actor->is($invitee) && $organisation->is($team));

        $this->actingAs($invitee)->postJson("/invitations/{$invitation->token}/redeem", [
            'agreementMembers' => [['key' => 'terms', 'version' => $this->member->version, 'digest' => $this->member->digest]],
        ])->assertOk();

        $entry = LedgerEntry::query()->sole();
        $membership = Membership::query()->where('team_id', $team->getKey())->where('user_id', $invitee->getKey())->sole();
        self::assertSame((string) $invitee->getKey(), $entry->party_id);
        self::assertSame((string) $team->getKey(), $entry->principal_id);
        self::assertNull($entry->subject_id, 'organisation-scoped entries have no subject');
        self::assertSame('form', $entry->method);
        self::assertNotNull($invitation->fresh()->accepted_at);
        self::assertNotNull($membership->getKey());

    }

    public function test_invitation_refuses_organisation_assent_without_the_registered_ability(): void
    {
        $owner = $this->user('Olive Owner', 'owner@example.test');
        $team = app(TeamProvisioner::class)->createTeamFor($owner, 'Acme');
        $otherInvitee = $this->user('Unauthorised Invitee', 'unauthorised@example.test');
        $otherInvitation = $this->invitation($owner, $team, $otherInvitee->email);

        app(RequirementRegistry::class)->attach('invitations.redeem', 'terms', AttachedRequirement::ORGANISATION);
        app()->instance(ActingOrganisation::class, new TestActingOrganisation($team));
        Gate::define('agreements.accept-for-team', static fn (): bool => false);

        $this->actingAs($otherInvitee)->postJson("/invitations/{$otherInvitation->token}/redeem", [
            'agreementMembers' => [['key' => 'terms', 'version' => $this->member->version, 'digest' => $this->member->digest]],
        ])->assertStatus(428)->assertJsonPath('requirements.0.key', 'terms');

        self::assertFalse(Membership::query()->where('team_id', $team->getKey())->where('user_id', $otherInvitee->getKey())->exists());
        self::assertNull($otherInvitation->fresh()->accepted_at);
        self::assertSame(0, LedgerEntry::query()->count());
    }

    private function user(string $name, string $email): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'password-1234']);
    }

    private function invitation(Model $owner, Team $team, string $email): Invitation
    {
        return Invitation::create([
            'team_id' => (string) $team->getKey(),
            'email' => $email,
            'role' => Role::Member->value,
            'token' => bin2hex(random_bytes(24)),
            'invited_by' => $owner->getKey(),
        ]);
    }

    private function separateCentralLedger(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.central' => config("database.connections.{$default}")]);
        DB::purge('central');

        $migrations = __DIR__.'/../vendor/splicewire/laravel-beam-agreements/database/migrations/shared/';
        DB::setDefaultConnection('central');
        try {
            (require $migrations.'create_agreement_ledger_tables.php.stub')->up();
            (require $migrations.'add_summary_to_agreement_versions.php.stub')->up();
            (require $migrations.'create_agreement_consent_requests_table.php.stub')->up();
        } finally {
            DB::setDefaultConnection($default);
        }

        app()->instance(TenancyContext::class, new class implements TenancyContext
        {
            public function isMultiTenant(): bool { return true; }

            public function isActive(): bool { return true; }

            public function currentTenant(): ?object { return (object) ['id' => 'tenant-1']; }

            public function currentTenantId(): int|string|null { return 'tenant-1'; }

            public function centralConnection(): ?string { return 'central'; }
        });

        $version = AgreementLedger::ledger()->freeze(app(AgreementRegistry::class)->get('terms'), 'v1', now()->subDay(), true);
        $this->member = new AgreementMemberData('terms', 1, $version->digest);
    }
}

final class TestActingOrganisation implements ActingOrganisation
{
    public function __construct(private Model $organisation) {}

    public function current(): ?Model
    {
        return $this->organisation;
    }
}
