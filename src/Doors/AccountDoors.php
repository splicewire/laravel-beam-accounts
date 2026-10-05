<?php

namespace Splicewire\Beam\Accounts\Doors;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Features;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;

/**
 * Who may become a user here: ONE declared policy and the ONLY central User create (purchase-walkthrough M10, rule
 * BUY-9). A host declares `beam.accounts.doors` once; this decides each door from it, Fortify's registration feature
 * follows it, and every way an account comes into existence goes through {@see create()}.
 *
 * Read live from config, never cached, so a host or a test may set the policy after boot.
 */
final class AccountDoors
{
    public function __construct(private readonly InvitationRedemption $invitations) {}

    /**
     * Whether this host has DECLARED its registration door. Until it does, Fortify's own feature list stands (adopting the
     * package changes no live host), while the package's own registration action still admits nobody.
     */
    public function declared(): bool
    {
        return config('beam.accounts.doors.registration') !== null;
    }

    public function policy(): AccountDoorsData
    {
        $doors = (array) config('beam.accounts.doors', []);
        $oauth = (array) ($doors['oauth'] ?? []);

        return new AccountDoorsData(
            registration: ($doors['registration'] ?? 'closed') === 'open' ? 'open' : 'closed',
            oauth: [
                'providers' => array_values(array_map('strval', (array) ($oauth['providers'] ?? []))),
                'create' => ($oauth['create'] ?? 'never') === 'domains' ? 'domains' : 'never',
                'domains' => array_values(array_map('strval', (array) ($oauth['domains'] ?? []))),
            ],
            operator: (bool) ($doors['operator'] ?? true),
        );
    }

    /** Whether `$door` admits `$email` (an invitation claim also names its token). */
    public function admit(Door $door, string $email, ?string $invitationToken = null): Admission
    {
        $policy = $this->policy();

        return match ($door) {
            Door::Register => $policy->registration === 'open'
                ? Admission::admit()
                : Admission::refuse('public registration is closed at this host.'),
            Door::OAuth => $this->admitOAuth($policy, $email),
            Door::Invite => $this->admitInvite($email, $invitationToken),
            Door::Operator => $policy->operator
                ? Admission::admit()
                : Admission::refuse('this host does not let an operator create accounts.'),
            Door::Service => Admission::admit(),
        };
    }

    /**
     * The one central User create: admitted through `$door`, or nothing is created.
     *
     * It admits but does not CONSUME: a caller creating through {@see Door::Invite} marks the invitation accepted in the
     * same transaction (tower's claim does), and a caller creating through {@see Door::Operator} or {@see Door::Service}
     * has already authorized its actor; admit() does not look at one (review-r1 on 56ea4f5).
     */
    public function create(Door $door, NewUserData $user, ?string $invitationToken = null): Authenticatable
    {
        $admission = $door === Door::OAuth && ! $user->verified
            ? Admission::refuse('the provider has not verified this email.')
            : $this->admit($door, $user->email, $invitationToken);

        if (! $admission->admitted) {
            throw DoorClosed::for($door, $admission);
        }

        $model = BeamAccounts::userModel();
        $created = $model::create(array_filter([
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
        ], fn ($value) => $value !== null));

        if ($user->verified) {
            $created->forceFill(['email_verified_at' => now()])->save();
        }

        event(new AccountCreated($created, $door));

        return $created;
    }

    /**
     * Fortify's feature list with registration following the policy: present exactly when it is open.
     *
     * @param  list<mixed>  $features
     * @return list<mixed>
     */
    public function fortifyFeatures(array $features): array
    {
        if (! $this->declared()) {
            return $features;
        }

        $without = array_values(array_filter($features, fn ($feature) => $feature !== Features::registration()));

        return $this->policy()->registration === 'open' ? [...$without, Features::registration()] : $without;
    }

    private function admitOAuth(AccountDoorsData $policy, string $email): Admission
    {
        if ($policy->oauth['create'] !== 'domains') {
            return Admission::refuse('this host creates no account from an OAuth sign-in.');
        }

        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
        $allowed = array_map(fn (string $d) => strtolower(ltrim(trim($d), '@')), $policy->oauth['domains']);

        return $domain !== '' && in_array($domain, $allowed, true)
            ? Admission::admit()
            : Admission::refuse("[{$domain}] is not a domain this host admits.");
    }

    private function admitInvite(string $email, ?string $token): Admission
    {
        $invitation = $token === null ? null : Invitation::query()->where('token', $token)->first();

        if ($invitation === null) {
            return Admission::refuse('no invitation carries that token.');
        }
        if ($invitation->accepted_at !== null) {
            return Admission::refuse('the invitation has been used.');
        }
        if (strcasecmp(trim($email), trim((string) $invitation->email)) !== 0) {
            return Admission::refuse('the invitation is addressed to another email.');
        }
        if ($this->invitations->expiresAt($invitation)->isPast()) {
            return Admission::refuse('the invitation has expired.');
        }

        return Admission::admit();
    }
}
