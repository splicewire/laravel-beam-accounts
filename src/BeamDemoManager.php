<?php

namespace Splicewire\Beam\Accounts;

use InvalidArgumentException;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

/**
 * The demo subjects — a standardized set of known identities at known access levels so any
 * satellite can be entered as owner/admin/member/solo and its account, billing, and team-admin
 * surfaces verified. Provisioned by {@see DemoTeamSeeder}, targeted by the
 * `splicewire:beam:accounts:login-as` affordance. Development/preview only — never real end-users.
 *
 * Fronted by {@see BeamDemo}, and deliberately a *separate* facade from
 * {@see BeamAccounts} rather than a prefixed corner of it: this whole surface is off in production,
 * and a front door that is sometimes closed does not belong on the package's production front door.
 * Its own facade also means the demo vocabulary reads as demo vocabulary at every call site.
 *
 * The roster is **derived from the {@see Role} enum**, not a hand-authored list: one shared-team
 * subject per role case (Owner is the team creator; the invitable roles join as members), plus a
 * `solo` subject that models the default team-of-one shape. Add a `Role` case → a fully-provisioned
 * demo subject appears in every satellite with no seeder edit. This honours the enum's own contract
 * ("everything that needs a role vocabulary derives from these cases; there is no parallel list
 * anywhere") — the old hardcoded `SUBJECTS` map was exactly the parallel list the enum forbids.
 */
class BeamDemoManager
{
    /**
     * The demo subject key {@see DemoTeamSeeder} grants root/operator reach onto (every
     * provisioned realm's root, via {@see DemoTeamSeeder::grantRealmReach()}, PLUS `is_staff`
     * where that host column exists) — the one demo account meant to be discoverable and
     * testable AS "the operator/root account", not just an ordinary Owner/Admin/Member row.
     * `admin` (not `owner`): mirrors the role that's `Role::grantEligible()` without being the
     * team's literal creator, so an "operator" reads as a granted capability, not an identity.
     */
    public const OPERATOR_KEY = 'admin';

    /**
     * The designated operator/root demo subject key ({@see self::OPERATOR_KEY}), reachable through
     * the facade — a `const` cannot ride `__callStatic`.
     */
    public function operatorKey(): string
    {
        return self::OPERATOR_KEY;
    }

    /**
     * key => {role, shared}. `shared` subjects sit together on one "Demo Team" (so the
     * role gates can be checked side by side); `solo` gets its own team-of-one (the
     * default satellite shape on registration). Role-derived; `solo` is the one hand-named
     * structural extra (a shape, not a role).
     *
     * @return array<string, array{role: Role, shared: bool}>
     */
    public function subjects(): array
    {
        $subjects = [];

        foreach (Role::cases() as $role) {
            $subjects[$role->value] = ['role' => $role, 'shared' => true];
        }

        $subjects['solo'] = ['role' => Role::Owner, 'shared' => false];

        return $subjects;
    }

    /** Whether `$key` is the designated operator/root demo subject ({@see self::OPERATOR_KEY}). */
    public function isOperator(string $key): bool
    {
        return $key === self::OPERATOR_KEY;
    }

    /**
     * Are the demo affordances live? Explicit config wins; otherwise on everywhere but
     * production.
     */
    public function enabled(): bool
    {
        $flag = config('beam.accounts.demo.enabled');

        if ($flag !== null) {
            return (bool) $flag;
        }

        return ! app()->environment('production');
    }

    /**
     * May this host PUBLISH signed login-as links into a page a guest can load — i.e. is it in
     * "demo mode"? Reads `beam.accounts.demo.login_links`, which ships **false** and is the one
     * key a host flips to get one-click demo sign-in.
     *
     * ## Why this is a second key and not {@see self::enabled()}
     *
     * `enabled()` answers "do the demo SUBJECTS exist here" — it gates seeding, the
     * `splicewire:beam:accounts:login-as` command, and the operation's own environment check. Its
     * null default is *on everywhere but production*, which is right for those: they are reached
     * by someone who already holds a shell or a session.
     *
     * Publishing is a different question with a different blast radius. A minted link is a
     * **bearer credential** — whoever loads the anonymous login page becomes that demo subject
     * with nothing typed, bounded only by the link's expiry. A default of "on in every
     * non-production environment" would hand that to every preview deploy, staging box and shared
     * dev host in the estate the moment it installed the package. So this key is the narrower one,
     * it defaults OFF, and it is read at the point the links are minted for a page
     * ({@see \Splicewire\Beam\Accounts\Actions\DemoLoginLinks::all()}) rather than in whatever
     * component renders them — a gate that lives only in the frontend is a gate anyone can walk
     * around by reading the page's props.
     *
     * ## Fail closed, deliberately
     *
     * Only a *strict* true — `true`, `1`, `'true'`, `'1'`, `'on'`, `'yes'` (Laravel's `env()`
     * already folds the first four) — turns this on. An absent key, a null, an empty string, and
     * anything misspelled (`'ture'`, `'enabled'`, `'demo'`) all read as OFF. `(bool)` would make a
     * typo mean ON, which is the wrong way for a credential-publishing switch to fail.
     *
     * `enabled()` must ALSO hold: a host that turned the demo subjects off has turned the links
     * off, and cannot re-enable them through this key alone.
     */
    public function publishesLoginLinks(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $flag = config('beam.accounts.demo.login_links');

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_int($flag)) {
            return $flag === 1;
        }

        return is_string($flag)
            && in_array(strtolower(trim($flag)), ['true', '1', 'on', 'yes'], true);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->subjects());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->subjects());
    }

    /** The role a demo subject holds on its team. */
    public function roleFor(string $key): Role
    {
        return $this->subjects()[$key]['role']
            ?? throw new InvalidArgumentException("Unknown demo subject [{$key}].");
    }

    /** Whether the subject sits on the shared "Demo Team" (vs its own team-of-one). */
    public function isShared(string $key): bool
    {
        return $this->subjects()[$key]['shared'] ?? false;
    }

    public function email(string $key): string
    {
        $domain = config('beam.accounts.demo.email_domain', 'example.test');

        return "demo-{$key}@{$domain}";
    }

    /**
     * The display label a login-as/demo-account button shows. `self::OPERATOR_KEY` reads as
     * "Demo Operator" — not "Demo Admin" — since its capability (root/operator reach), not its
     * team role, is the notable, testable thing about it.
     */
    public function name(string $key): string
    {
        return $this->isOperator($key) ? 'Demo Operator' : 'Demo '.ucfirst($key);
    }
}
