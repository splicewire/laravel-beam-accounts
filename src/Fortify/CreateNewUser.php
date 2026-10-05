<?php

namespace Splicewire\Beam\Accounts\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Accounts\Concerns\ProfileValidationRules;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Doors\Door;
use Splicewire\Beam\Accounts\Doors\NewUserData;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;

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
     * @param  array<string, string>  $input
     */
    public function create(array $input): Authenticatable
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = $this->createUser($input);

        $this->afterCreating($user, $input);

        return $user;
    }

    /**
     * @param  array<string, string>  $input
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
     * @param  array<string, string>  $input
     */
    protected function afterCreating(Authenticatable $user, array $input): void
    {
        //
    }
}
