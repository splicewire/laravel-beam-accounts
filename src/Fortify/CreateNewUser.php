<?php

namespace Splicewire\Beam\Accounts\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Accounts\Concerns\ProfileValidationRules;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Doors\Door;
use Splicewire\Beam\Accounts\Doors\NewUserData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Agreements\AccountAssentRecorder;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Write\WriteRequirement;

/**
 * The shared registration action, door-checked (purchase-walkthrough M10): validate, then create the user through
 * {@see AccountDoors::create()} at the registration door, which refuses unless `beam.accounts.doors.registration` is
 * open. No team is provisioned (lead ruling 2026-10-05, the starters' behaviour): a host that wants a personal team
 * calls `TeamProvisioner::personalTeamFor()` from afterCreating(). Hosts extend this rather than copying it.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(protected TeamProvisioner $teams, protected AccountDoors $doors) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): Authenticatable
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules('passwordConfirmation'),
            'password_confirmation' => ['prohibited'],
        ])->validate();

        $behavior = 'auth.register';
        if (! app()->bound(RequirementDeclarations::class)
            || ! app()->bound(RequirementAdmission::class)
            || ! app()->bound(AssentRecorder::class)) {
            $user = $this->createUser($input);
            $this->afterCreating($user, $input);

            return $user;
        }

        $declarations = app(RequirementDeclarations::class);
        $requirements = $declarations->requirementsFor($behavior);
        $members = self::submittedMembers($input);

        if ($requirements !== []) {
            $refusal = app(RequirementAdmission::class)->inspect(null, $behavior, null, $members);

            if ($refusal !== null) {
                throw new RequirementRefused($refusal, $behavior);
            }
        }

        if ($requirements === []) {
            $user = $this->createUser($input);
            $this->afterCreating($user, $input);

            return $user;
        }

        $userModel = BeamAccounts::userModel();
        $connection = (new $userModel)->getConnection();

        return $connection->transaction(function () use ($input, $members, $requirements, $behavior): Authenticatable {
            $user = $this->createUser($input);
            $this->afterCreating($user, $input);

            if ($user instanceof Model && $members !== []) {
                if (app()->bound(AccountAssentRecorder::class)) {
                    app(AccountAssentRecorder::class)->record(array_values($members), $user, $user, null, $behavior);
                } elseif (app()->bound(AssentRecorder::class)) {
                    app(AssentRecorder::class)->record($user, new WriteRequirement(
                        behavior: $behavior,
                        subject: null,
                        submittedMembers: $members,
                        actor: $user,
                    ));
                }
            }

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function createUser(array $input): Authenticatable
    {
        return $this->doors->create(Door::Register, new NewUserData(
            name: $input['name'],
            email: $input['email'],
            password: $input['password'],
        ));
    }

    /**
     * Consumer hook — runs after the user + team-of-one exist. No-op by default.
     *
     * @param  array<string, mixed>  $input
     */
    protected function afterCreating(Authenticatable $user, array $input): void
    {
        //
    }

    /** @return array<string, AgreementMemberData> */
    private static function submittedMembers(array $input): array
    {
        $values = $input['agreementMembers'] ?? [];
        if (! is_array($values)) {
            return [];
        }

        $members = [];
        foreach ($values as $value) {
            if (is_array($value)
                && is_string($value['key'] ?? null)
                && is_int($value['version'] ?? null)
                && is_string($value['digest'] ?? null)) {
                $members[$value['key']] = new AgreementMemberData($value['key'], $value['version'], $value['digest']);
            }
        }

        return $members;
    }
}
