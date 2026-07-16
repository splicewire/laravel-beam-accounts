<?php

namespace Schemastud\Beam\Accounts\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

use function Schemastud\Beam\Accounts\accountUserModel;

use Schemastud\Beam\Accounts\Concerns\PasswordValidationRules;
use Schemastud\Beam\Accounts\Concerns\ProfileValidationRules;
use Schemastud\Beam\Accounts\Teams\TeamProvisioner;

/**
 * The shared registration action: validate, create the satellite's user, and
 * provision a team-of-one. Satellites extend this and override afterCreating()
 * for app-specific side effects (gift redemption, invite claims, …) instead of
 * copy-pasting the whole action.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(protected TeamProvisioner $teams) {}

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

        $this->teams->personalTeamFor($user);

        $this->afterCreating($user, $input);

        return $user;
    }

    /**
     * @param  array<string, string>  $input
     */
    protected function createUser(array $input): Authenticatable
    {
        $model = accountUserModel();

        return $model::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
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
