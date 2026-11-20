<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Authorization\DecisionNames;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\Popcorn\Registries\RegistryIndex;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Contracts\DecisionNameSource;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\PolicedWidget;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tokens\CascadeDecisionNameSource;
use Splicewire\Beam\Accounts\Tokens\DelegatedTokenKind;
use Splicewire\Beam\Accounts\Tokens\MachineTokenIssuer;
use Splicewire\Beam\Accounts\Tokens\OperationTokenKind;
use Splicewire\Beam\Accounts\Tokens\SamePrincipalTokenKind;
use Splicewire\Beam\Accounts\Tokens\TokenIssuance;
use Splicewire\Beam\Accounts\Tokens\TokenKindRegistry;
use Splicewire\Beam\Accounts\Tokens\WidestTokenScope;
use Splicewire\Beam\Localization\CodedValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * credential-scope 03 (fleet ADR 0006 rules 2 and 3; C5, C6, C7): the one machine-token issuer.
 *
 * The acting credential's scope is the cascade's CredentialScopeResolver (beam-accounts binds the bearer resolver
 * there when enforcement is on), bound here to a fixed list; null is a session or a `*` login token.
 */
beforeEach(function () {
    $this->createPersonalAccessTokensSchema();
    config(['beam.accounts.api.scope_enforcement' => true]);

    $owner = User::create(['name' => 'Owner', 'email' => 'cs03-owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => false]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    foreach (['cs03.read', 'cs03.write'] as $name) {
        app(config('permission.models.permission'))::findOrCreate($name, 'web');
    }
    $owner->givePermissionTo(['cs03.read', 'cs03.write']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->owner = $owner->fresh();
    $this->team = $team;
    $this->issuer = app(MachineTokenIssuer::class);

    cs03ActingScope(null);
});

/** @param  list<string>|null  $scope */
function cs03ActingScope(?array $scope): void
{
    app()->instance(CredentialScopeResolver::class, new class($scope) implements CredentialScopeResolver
    {
        public function __construct(private ?array $scope) {}

        public function resolve(): ?array
        {
            return $this->scope;
        }
    });
}

/** Run `$mint` and return the refusal's stable code (a coded 422 field code or a coded HTTP refusal). */
function cs03RefusalCode(Closure $mint): string
{
    try {
        $mint();
    } catch (CodedValidationException $e) {
        return $e->codes()['abilities'][0] ?? 'uncoded-validation';
    } catch (ValidationException $e) {
        return 'uncoded-validation';
    } catch (HttpException $e) {
        return $e->error->code();
    }

    throw new RuntimeException('expected a refusal, but the token was issued');
}

function cs03Pat(?Authenticatable $actor, ?array $abilities, string $name = 'cli'): TokenIssuance
{
    return new TokenIssuance(actor: $actor, name: $name, abilities: $abilities);
}

/** A declared delegated kind: a team's service principal, minted by the team's owner (fixtures below). */
function cs03DeclareSatelliteKind(): void
{
    config(['beam.accounts.tokens.kinds.cs03-satellite' => Cs03SatelliteKind::class]);
}

function cs03ServicePrincipal(Team $team): User
{
    return User::firstOrCreate(['email' => "service-{$team->getKey()}@cs03.test"], ['name' => 'Service', 'password' => 'x']);
}

// ── personal-access-token: never `*`, the widest named set instead ─────────────────────────────

it('turns a `*` or empty personal-token request into the widest named set, never `*`', function () {
    foreach ([['*'], [], null] as $requested) {
        $issued = $this->issuer->issue('personal-access-token', cs03Pat($this->owner, $requested));

        expect($issued->token->abilities)->toEqualCanonicalizing(app(WidestTokenScope::class)->for($this->owner))
            ->toContain('cs03.read', 'cs03.write')
            ->and($issued->token->abilities)->not->toContain('*');
    }
});

it('records the kind and provenance on a row of the configured token model, and the bearer finds it', function () {
    $issued = $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read']));
    $model = BeamAccounts::tokenModel();

    expect($issued->token)->toBeInstanceOf($model)
        ->and($issued->token->fresh()->kind)->toBe('personal-access-token')
        ->and($issued->token->fresh()->provenance)->toBe(TokenProvenance::Api)
        ->and($issued->token->fresh()->abilities)->toBe(['cs03.read'])
        ->and((string) $issued->token->tokenable_id)->toBe((string) $this->owner->getKey())
        ->and($model::findToken($issued->plainTextToken)?->is($issued->token))->toBeTrue();
});

it('refuses a personal-token request outside the widest set with token_abilities', function () {
    expect(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read', 'not.held']))))
        ->toBe('token_abilities');
});

// ── C7: undeclared kinds and `*` are refused ──────────────────────────────────────────────────

it('refuses an undeclared kind and writes nothing', function () {
    $before = BeamAccounts::tokenModel()::count();

    expect(cs03RefusalCode(fn () => $this->issuer->issue('not-a-kind', cs03Pat($this->owner, ['cs03.read']))))->toBe('token_kind_undeclared')
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('Not A Kind', cs03Pat($this->owner, ['cs03.read']))))->toBe('token_kind_undeclared')
        ->and(BeamAccounts::tokenModel()::count())->toBe($before);
});

it('refuses `*` from any kind, whatever the kind returns', function () {
    config(['beam.accounts.tokens.kinds.cs03-wild' => Cs03WildcardKind::class]);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-wild', cs03Pat($this->owner, null))))->toBe('token_wildcard_refused')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('does not treat a bare TokenKind subclass as a declared kind', function () {
    config(['beam.accounts.tokens.kinds.cs03-bare' => Cs03BareKind::class]);

    expect(app(TokenKindRegistry::class)->kind('cs03-bare'))->toBeNull()
        ->and(app(TokenKindRegistry::class)->kind('personal-access-token'))->not->toBeNull();
});

// ── C5: nothing is minted while enforcement is off ────────────────────────────────────────────

it('refuses every machine kind while scope_enforcement is off', function () {
    config(['beam.accounts.api.scope_enforcement' => false]);
    cs03DeclareSatelliteKind();

    expect(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read']))))->toBe('token_issuer_enforcement_off')
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 's'))))->toBe('token_issuer_enforcement_off')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

// ── C6, same principal: capped at the acting credential ───────────────────────────────────────

it('refuses a scoped acting token minting a wider same-principal token, coded and with nothing written', function () {
    cs03ActingScope(['cs03.read']);

    foreach ([['cs03.read', 'cs03.write'], ['*'], [], null] as $requested) {
        expect(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', cs03Pat($this->owner, $requested))))
            ->toBe('token_abilities_beyond_acting_token');
    }

    expect(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('lets a scoped acting token mint an equal or narrower same-principal token', function () {
    cs03ActingScope(['cs03.read', 'cs03.write']);

    expect($this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read', 'cs03.write']))->token->abilities)->toBe(['cs03.read', 'cs03.write'])
        ->and($this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read']))->token->abilities)->toBe(['cs03.read']);
});

it('leaves a session or `*` acting credential uncapped (it still cannot get `*`)', function () {
    cs03ActingScope(null);

    expect($this->issuer->issue('personal-access-token', cs03Pat($this->owner, null))->token->abilities)
        ->toEqualCanonicalizing(app(WidestTokenScope::class)->for($this->owner))
        ->not->toContain('*');
});

it('refuses a same-principal kind with no actor, or for a principal other than the actor', function () {
    $other = User::create(['name' => 'Other', 'email' => 'cs03-other@example.test', 'password' => 'x']);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', cs03Pat(null, ['cs03.read']))))->toBe('token_actor_required')
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', new TokenIssuance(actor: $this->owner, name: 'x', abilities: ['cs03.read'], principal: $other))))
        ->toBe('token_target_mismatch');
});

it('leaves the login token unchanged: AuthTokenFactory still mints `*`, under a scoped acting token too', function () {
    cs03ActingScope(['cs03.read']);
    $user = Cs03TokenUser::create(['name' => 'Login', 'email' => 'cs03-login@example.test', 'password' => 'x']);

    $token = AuthTokenFactory::mint($user, 'Mozilla/5.0', TokenProvenance::Session, false);

    expect($token->accessToken->abilities)->toBe(['*'])
        ->and($token->accessToken->fresh()->kind)->toBeNull();
});

// ── rotate ───────────────────────────────────────────────────────────────────────────────────

it('rotates within the acting token and refuses rotating a wider one', function () {
    $wide = $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read', 'cs03.write'], 'wide'))->token;
    $narrow = $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read'], 'narrow'))->token;

    cs03ActingScope(['cs03.read']);

    expect(cs03RefusalCode(fn () => $this->issuer->rotate($wide, cs03Pat($this->owner, null, ''))))->toBe('token_abilities_beyond_acting_token');

    $rotated = $this->issuer->rotate($narrow, cs03Pat($this->owner, null, ''));

    expect($rotated->token->abilities)->toBe(['cs03.read'])
        ->and($rotated->token->name)->toBe('narrow')
        ->and($rotated->token->kind)->toBe('personal-access-token')
        ->and($rotated->token->is($narrow))->toBeFalse();
});

it('refuses rotating another principal\'s token, a delegated kind and a row with no recorded kind', function () {
    $mine = $this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read']))->token;
    $other = User::create(['name' => 'Other', 'email' => 'cs03-other2@example.test', 'password' => 'x']);

    cs03DeclareSatelliteKind();
    $satellite = $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 'sat'))->token;

    $model = BeamAccounts::tokenModel();
    $legacy = (new $model)->forceFill([
        'tokenable_type' => $this->owner->getMorphClass(), 'tokenable_id' => $this->owner->getKey(),
        'name' => 'legacy', 'token' => hash('sha256', 'legacy'), 'abilities' => ['*'],
    ]);
    $legacy->save();

    expect(cs03RefusalCode(fn () => $this->issuer->rotate($mine, cs03Pat($other, null, ''))))->toBe('token_target_mismatch')
        ->and(cs03RefusalCode(fn () => $this->issuer->rotate($satellite, cs03Pat($this->owner, null, ''))))->toBe('token_kind_not_rotatable')
        ->and(cs03RefusalCode(fn () => $this->issuer->rotate($legacy, cs03Pat($this->owner, null, ''))))->toBe('token_kind_undeclared');
});

// ── C6, a different principal: a declared delegated kind ──────────────────────────────────────

it('mints a delegated kind\'s declared set for its resolved target, uncapped by the acting token\'s abilities', function () {
    cs03DeclareSatelliteKind();
    cs03ActingScope(['cs03-satellite:issue']);

    $issued = $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 'satellite:T'));
    $service = cs03ServicePrincipal($this->team);

    expect($issued->token->abilities)->toBe(['widget.view', 'widget.update'])
        ->and((string) $issued->token->tokenable_id)->toBe((string) $service->getKey())
        ->and($issued->token->fresh()->kind)->toBe('cs03-satellite')
        ->and($issued->token->fresh()->provenance)->toBe(TokenProvenance::Service);
});

it('refuses a delegated kind when the acting credential lacks its issuance ability', function () {
    cs03DeclareSatelliteKind();
    cs03ActingScope(['widget.view', 'widget.update']);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 's'))))
        ->toBe('token_issuance_ability_missing');
});

it('refuses a delegated kind when the actor has no live authority over the target', function () {
    cs03DeclareSatelliteKind();
    $member = User::create(['name' => 'Member', 'email' => 'cs03-member@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $this->team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
    $member->switchTeam($this->team);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $member->fresh(), name: 's'))))
        ->toBe('token_target_no_authority');
});

it('refuses a delegated kind when the caller names a different principal or tenant', function () {
    cs03DeclareSatelliteKind();
    $stranger = User::create(['name' => 'Stranger', 'email' => 'cs03-stranger@example.test', 'password' => 'x']);
    $otherTeam = Team::create(['user_id' => $stranger->id, 'name' => 'Other', 'personal_team' => false]);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 's', principal: $stranger))))
        ->toBe('token_target_mismatch')
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 's', subject: $otherTeam))))
        ->toBe('token_target_mismatch')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('refuses a delegated request that names abilities, and one whose target does not resolve', function () {
    cs03DeclareSatelliteKind();
    $teamless = User::create(['name' => 'Teamless', 'email' => 'cs03-teamless@example.test', 'password' => 'x']);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $this->owner, name: 's', abilities: ['widget.view']))))
        ->toBe('token_kind_declares_abilities')
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $teamless, name: 's'))))
        ->toBe('token_target_unresolved');
});

it('refuses a delegated kind whose target resolves to the actor, under a scoped token, and writes nothing (codex cs03 #1)', function () {
    config(['beam.accounts.tokens.kinds.cs03-self' => Cs03SelfKind::class]);
    cs03ActingScope(['cs03-self:issue']);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs03-self', new TokenIssuance(actor: $this->owner, name: 'self'))))
        ->toBe('token_target_is_actor')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('refuses a caller-named tenant when the kind resolves no tenant of its own, even one the actor owns (codex cs03 #2)', function () {
    cs03DeclareSatelliteKind();
    $noCurrent = User::create(['name' => 'NoCurrent', 'email' => 'cs03-nocurrent@example.test', 'password' => 'x']);
    $owned = Team::create(['user_id' => $noCurrent->id, 'name' => 'Owned', 'personal_team' => false]);
    Membership::create(['team_id' => $owned->id, 'user_id' => $noCurrent->id, 'role' => Role::Owner->value]);

    expect($noCurrent->fresh()->currentTeam)->toBeNull()
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('cs03-satellite', new TokenIssuance(actor: $noCurrent->fresh(), name: 's', subject: $owned))))
        ->toBe('token_target_mismatch')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

// ── an operation-bound delegated kind (credential-scope 04) ──────────────────────────────────

it('mints an operation kind for the operation in hand, its target resolved from it and the actor\'s authority over it checked', function () {
    config(['beam.accounts.tokens.kinds.cs04-team-service' => Cs04TeamServiceKind::class]);
    $issued = $this->issuer->issueFor('cs04-team-service', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc'));

    expect((string) $issued->token->tokenable_id)->toBe((string) cs03ServicePrincipal($this->team)->getKey())
        ->and($issued->token->abilities)->toBe(['widget.view'])
        ->and($issued->token->fresh()->kind)->toBe('cs04-team-service');

    // A team the actor does not own: the operation is not authority, so it is refused and nothing is written.
    $stranger = User::create(['name' => 'Stranger', 'email' => 'cs04-stranger@example.test', 'password' => 'x']);
    $foreign = Team::create(['user_id' => $stranger->id, 'name' => 'Foreign', 'personal_team' => false]);
    $before = BeamAccounts::tokenModel()::count();

    expect(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-service', $foreign, new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_target_no_authority')
        ->and(BeamAccounts::tokenModel()::count())->toBe($before);
});

it('refuses an operation kind issued without its operation, a caller claim of another subject, and an operation for a kind of any other shape', function () {
    config(['beam.accounts.tokens.kinds.cs04-team-service' => Cs04TeamServiceKind::class]);
    cs03DeclareSatelliteKind();
    $other = Team::create(['user_id' => $this->owner->id, 'name' => 'Other', 'personal_team' => false]);

    expect(cs03RefusalCode(fn () => $this->issuer->issue('cs04-team-service', new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_target_unresolved')
        ->and(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-service', null, new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_target_unresolved')
        ->and(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-service', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc', subject: $other))))
        ->toBe('token_target_mismatch')
        ->and(cs03RefusalCode(fn () => $this->issuer->issueFor('cs03-satellite', $this->team, new TokenIssuance(actor: $this->owner, name: 's'))))
        ->toBe('token_kind_not_operation_bound')
        ->and(cs03RefusalCode(fn () => $this->issuer->issueFor('personal-access-token', $this->team, cs03Pat($this->owner, null))))
        ->toBe('token_kind_not_operation_bound')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('applies every delegated rule to an operation kind: issuance ability, enforcement, and no `*`', function () {
    config(['beam.accounts.tokens.kinds.cs04-team-service' => Cs04TeamServiceKind::class]);

    cs03ActingScope(['widget.view']);
    expect(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-service', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_issuance_ability_missing');

    cs03ActingScope(['cs04-team-service:issue']);
    expect($this->issuer->issueFor('cs04-team-service', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc'))->token->abilities)->toBe(['widget.view']);

    config(['beam.accounts.tokens.kinds.cs04-team-wildcard' => Cs04TeamWildcardKind::class]);
    expect(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-wildcard', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_wildcard_refused');

    config(['beam.accounts.api.scope_enforcement' => false]);
    expect(cs03RefusalCode(fn () => $this->issuer->issueFor('cs04-team-service', $this->team, new TokenIssuance(actor: $this->owner, name: 'svc'))))
        ->toBe('token_issuer_enforcement_off');
});

// ── one principal in either representation (codex cs04 #1) ─────────────────────────────────

it('refuses a delegated kind whose target is the actor\'s central user when the actor arrives as its tenant mirror', function () {
    config(['beam.accounts.tokens.kinds.cs04-central' => Cs04CentralKind::class]);
    cs03ActingScope(['cs04-central:issue']);
    $mirror = Cs04MirrorUser::query()->findOrFail($this->owner->getKey());

    expect($mirror)->not->toBeInstanceOf(User::class)
        ->and(cs03RefusalCode(fn () => $this->issuer->issue('cs04-central', new TokenIssuance(actor: $mirror, name: 'loop'))))
        ->toBe('token_target_is_actor')
        ->and(BeamAccounts::tokenModel()::count())->toBe(0);
});

it('owns a personal token by the central user and caps it at the acting scope when the actor is a mirror', function () {
    cs03ActingScope(['cs03.read']);
    $mirror = Cs04MirrorUser::query()->findOrFail($this->owner->getKey());

    expect(cs03RefusalCode(fn () => $this->issuer->issue('personal-access-token', cs03Pat($mirror, ['cs03.write']))))
        ->toBe('token_abilities_beyond_acting_token');

    $issued = $this->issuer->issue('personal-access-token', cs03Pat($mirror, ['cs03.read']));
    expect($issued->token->tokenable_type)->toBe($this->owner->getMorphClass())
        ->and((string) $issued->token->tokenable_id)->toBe((string) $this->owner->getKey());
});

it('refuses before any write a kind the acting credential cannot issue (authorizeIssuance, codex cs04 #4)', function () {
    cs03DeclareSatelliteKind();
    cs03ActingScope(['widget.view']);

    expect(cs03RefusalCode(fn () => $this->issuer->authorizeIssuance('cs03-satellite')))->toBe('token_issuance_ability_missing')
        ->and(cs03RefusalCode(fn () => $this->issuer->authorizeIssuance('cs04-nothing')))->toBe('token_kind_undeclared');

    cs03ActingScope(['cs03-satellite:issue']);
    $this->issuer->authorizeIssuance('cs03-satellite');
    cs03ActingScope(null);
    $this->issuer->authorizeIssuance('cs03-satellite');

    config(['beam.accounts.api.scope_enforcement' => false]);
    expect(cs03RefusalCode(fn () => $this->issuer->authorizeIssuance('cs03-satellite')))->toBe('token_issuer_enforcement_off');
});

// ── the widest set and its decision-name seam ─────────────────────────────────────────────────

it('computes the widest set as the permission snapshot plus every registered decision-name source, never `*`', function () {
    config(['beam.accounts.tokens.decision_name_sources' => [Cs03DecisionNames::class]]);

    $widest = app(WidestTokenScope::class)->for($this->owner);

    expect(array_slice($widest, 0, 2))->toBe(['cs03.read', 'cs03.write'])
        ->and($widest)->toContain('team.join', 'membership.view')->not->toContain('*')
        ->and(array_count_values($widest)['cs03.read'])->toBe(1)
        ->and($this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['team.join']))->token->abilities)->toBe(['team.join']);
});

it('builds the cascade\'s decision names in, even where a host\'s published tokens config has no source key (credential-scope 04)', function () {
    config(['beam.accounts.tokens' => ['model' => null, 'scope' => null]]);
    app(DecisionNames::class)->name('cs04Reboot', 'cs04.reboot');
    Relation::morphMap(['policed-widget' => PolicedWidget::class]);
    Gate::policy(PolicedWidget::class, Cs04WidgetPolicy::class);

    $widest = app(WidestTokenScope::class)->for($this->owner);

    // a registered name, and a policy verb's class-level name: what a scoped token must carry at those decisions
    expect($widest)->toContain('cs03.read', 'cs04.reboot', 'policed-widget.publish')
        ->and(WidestTokenScope::BUILT_IN_SOURCES)->toContain(CascadeDecisionNameSource::class)
        ->and(collect(app(CascadeDecisionNameSource::class)->namesFor($this->owner))->all())
        ->toBe(app(CredentialScopeGate::class)->offerableNames());

    // and the name it offers is the name the cascade asks at the decision
    expect(app(CredentialScopeGate::class)->namesFor('publish', [PolicedWidget::class]))->toContain('policed-widget.publish');
});

// ── deterministic keys ───────────────────────────────────────────────────────────────────────

it('issues a deterministic key: the same (id, plaintext) is the same bearer and one row', function () {
    $request = fn () => new TokenIssuance(actor: $this->owner, name: 'host-key', abilities: ['cs03.read'], deterministicId: '900001', deterministicPlaintext: 'fixed-secret');

    $first = $this->issuer->issue('personal-access-token', $request());
    $second = $this->issuer->issue('personal-access-token', $request());

    expect($first->plainTextToken)->toBe('900001|fixed-secret')
        ->and($second->plainTextToken)->toBe('900001|fixed-secret')
        ->and(BeamAccounts::tokenModel()::count())->toBe(1)
        ->and(BeamAccounts::tokenModel()::findToken('900001|fixed-secret')?->kind)->toBe('personal-access-token');
});

it('declares the kind registry into the popcorn index', function () {
    expect(app(RegistryIndex::class)->has('beam.accounts.tokens.kinds'))->toBeTrue()
        ->and(app(TokenKindRegistry::class)->names())->toContain('personal-access-token');
});

// ── fixtures ─────────────────────────────────────────────────────────────────────────────────

class Cs03WildcardKind extends SamePrincipalTokenKind
{
    public function abilities(Authenticatable $principal, ?array $requested): array
    {
        return ['*'];
    }
}

class Cs03BareKind extends Splicewire\Beam\Accounts\Tokens\TokenKind {}

/** A team's service principal, bound to the actor's current team and minted only by that team's owner. */
class Cs03SatelliteKind extends DelegatedTokenKind
{
    public function provenance(): TokenProvenance
    {
        return TokenProvenance::Service;
    }

    public function issuanceAbility(): string
    {
        return 'cs03-satellite:issue';
    }

    public function boundSubject(?Authenticatable $actor): mixed
    {
        return $actor?->currentTeam;
    }

    public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model
    {
        return $subject instanceof Team ? cs03ServicePrincipal($subject) : null;
    }

    public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool
    {
        return $actor !== null && $subject instanceof Team && (string) $subject->user_id === (string) $actor->getAuthIdentifier();
    }

    public function abilities(Model $target, mixed $subject): array
    {
        return ['widget.view', 'widget.update'];
    }
}

/** A delegated kind whose resolver (wrongly) answers the actor: the issuer must refuse it. */
class Cs03SelfKind extends DelegatedTokenKind
{
    public function issuanceAbility(): string
    {
        return 'cs03-self:issue';
    }

    public function boundSubject(?Authenticatable $actor): mixed
    {
        return $actor?->currentTeam;
    }

    public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model
    {
        return $actor;
    }

    public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool
    {
        return true;
    }

    public function abilities(Model $target, mixed $subject): array
    {
        return ['cs03.read', 'cs03.write', 'widget.delete'];
    }
}

/** A team's service principal, minted for the team the calling code is operating on, by that team's owner. */
class Cs04TeamServiceKind extends OperationTokenKind
{
    public function issuanceAbility(): string
    {
        return 'cs04-team-service:issue';
    }

    public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model
    {
        return $subject instanceof Team ? cs03ServicePrincipal($subject) : null;
    }

    public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool
    {
        return $actor !== null && $subject instanceof Team && (string) $subject->user_id === (string) $actor->getAuthIdentifier();
    }

    public function abilities(Model $target, mixed $subject): array
    {
        return ['widget.view'];
    }
}

class Cs04TeamWildcardKind extends Cs04TeamServiceKind
{
    public function abilities(Model $target, mixed $subject): array
    {
        return ['*'];
    }
}

class Cs04WidgetPolicy
{
    public function publish(Authenticatable $user, PolicedWidget $widget): bool
    {
        return true;
    }
}

/** A tenant-side mirror of a central user: another model class over the same key (tower's TenantUser shape). */
class Cs04MirrorUser extends Illuminate\Foundation\Auth\User
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function getMorphClass()
    {
        return 'tenant_user';
    }
}

/** A delegated kind whose target is the actor's central user (the engine loopback's shape for the sync identity). */
class Cs04CentralKind extends DelegatedTokenKind
{
    public function issuanceAbility(): string
    {
        return 'cs04-central:issue';
    }

    public function boundSubject(?Authenticatable $actor): mixed
    {
        return 'central';
    }

    public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model
    {
        return $actor === null ? null : User::query()->find($actor->getAuthIdentifier());
    }

    public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool
    {
        return true;
    }

    public function abilities(Model $target, mixed $subject): array
    {
        return ['cs03.read', 'cs03.write'];
    }
}

class Cs03DecisionNames implements DecisionNameSource
{
    public function namesFor(Authenticatable $user): iterable
    {
        return ['team.join', 'membership.view', '*', 'cs03.read'];
    }
}

class Cs03TokenUser extends User
{
    use HasApiTokens;
}

it('keeps the package\'s own kind declared when a host\'s published tokens config has no kinds key', function () {
    config(['beam.accounts.tokens' => ['model' => null, 'scope' => null, 'connection' => null]]);

    expect(app(TokenKindRegistry::class)->names())->toBe(['personal-access-token'])
        ->and($this->issuer->issue('personal-access-token', cs03Pat($this->owner, ['cs03.read']))->token->kind)->toBe('personal-access-token');
});
