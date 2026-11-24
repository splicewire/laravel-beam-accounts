<?php

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Agreements\AccountAssentRecorder;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;

beforeEach(function (): void {
    $this->agreementResolver = new Agreements14SignupResolver;
    app()->instance(RequirementAdmission::class, new RequirementAdmission($this->agreementResolver));
    app()->instance(RequirementDeclarations::class, new Agreements14SignupDeclarations);
    $this->assentRecorder = new Agreements14SignupRecorder;
    app()->instance(AccountAssentRecorder::class, $this->assentRecorder);
});

it('admits signup with the current agreement member and records it against the new account', function (): void {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
        'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($this->assentRecorder->calls)->toHaveCount(1)
        ->and($this->assentRecorder->calls[0][1]->is($user))->toBeTrue()
        ->and($this->assentRecorder->calls[0][2]->is($user))->toBeTrue()
        ->and($this->assentRecorder->calls[0][3])->toBeNull()
        ->and($this->assentRecorder->calls[0][4])->toBe('auth.register')
        ->and($this->assentRecorder->calls[0][0][0]->key)->toBe('terms');
});

it('admits and forwards one current member for each blocking Agreement', function (): void {
    $requirements = [new Requires('terms'), new Requires('privacy')];
    app()->instance(RequirementDeclarations::class, new Agreements14SignupDeclarations($requirements));
    app()->instance(RequirementAdmission::class, new RequirementAdmission(new Agreements14SignupResolver($requirements)));
    $recorder = $this->assentRecorder;

    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
        'agreementMembers' => [
            ['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest'],
            ['key' => 'privacy', 'version' => 4, 'digest' => 'privacy-digest'],
        ],
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and(array_map(fn (AgreementMemberData $member) => $member->key, $recorder->calls[0][0]))->toBe(['terms', 'privacy']);
});

it('refuses only the outstanding Agreement when a multi-member signup omits one', function (): void {
    $requirements = [new Requires('terms'), new Requires('privacy')];
    app()->instance(RequirementDeclarations::class, new Agreements14SignupDeclarations($requirements));
    app()->instance(RequirementAdmission::class, new RequirementAdmission(new Agreements14SignupResolver($requirements)));

    try {
        app(CreateNewUser::class)->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'password-1234',
            'passwordConfirmation' => 'password-1234',
            'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
        ]);
        $this->fail('the missing privacy Agreement did not refuse signup');
    } catch (RequirementRefused $refusal) {
        expect(array_map(fn ($item) => $item->key, $refusal->refusal->requirements))->toBe(['privacy']);
    }

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('refuses signup without the current agreement member before creating an account', function (): void {
    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
    ]))->toThrow(RequirementRefused::class);

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('refuses a stale signup member before creating an account', function (): void {
    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
        'agreementMembers' => [['key' => 'terms', 'version' => 2, 'digest' => 'old-digest']],
    ]))->toThrow(RequirementRefused::class);

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse()
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('rolls back signup when recording the admitted agreement fails', function (): void {
    $this->assentRecorder->fail = true;

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
        'agreementMembers' => [['key' => 'terms', 'version' => 3, 'digest' => 'frozen-digest']],
    ]))->toThrow(RuntimeException::class, 'assent append failed');

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('keeps the signup wire and recorded writes unchanged when no Requirement is declared', function (): void {
    app()->instance(RequirementDeclarations::class, new Agreements14SignupDeclarations([]));
    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($this->assentRecorder->calls)->toBeEmpty();
});

it('keeps signup available when the optional agreements seam is not installed', function (): void {
    app()->forgetInstance(RequirementDeclarations::class);
    app()->forgetInstance(RequirementAdmission::class);
    app()->forgetInstance(AccountAssentRecorder::class);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and(User::query()->where('email', 'ada@example.test')->exists())->toBeTrue();
});

final class Agreements14SignupDeclarations implements RequirementDeclarations
{
    /** @param list<Requires> $requirements */
    public function __construct(private array $requirements = [new Requires('terms')]) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $behavior === 'auth.register' ? $this->requirements : [];
    }

    public function all(): array
    {
        return $this->requirements === [] ? [] : ['auth.register' => $this->requirements];
    }
}

final class Agreements14SignupResolver implements RequirementResolver
{
    /** @param list<Requires> $requirements */
    public function __construct(private array $requirements = [new Requires('terms')]) {}

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        if ($behavior !== 'auth.register') {
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

final class Agreements14SignupRecorder implements AccountAssentRecorder
{
    /** @var list<array{list<AgreementMemberData>, Model, Model, ?Model, string}> */
    public array $calls = [];

    public bool $fail = false;

    public function record(array $members, Model $party, Model $subject, ?Model $organisation, string $behavior): void
    {
        $this->calls[] = [$members, $party, $subject, $organisation, $behavior];

        if ($this->fail) {
            throw new RuntimeException('assent append failed');
        }
    }
}
