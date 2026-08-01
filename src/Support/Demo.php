<?php

namespace Splicewire\Beam\Accounts\Support;

use InvalidArgumentException;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * The demo subjects — a standardized set of known identities at known access levels so
 * any satellite can be entered as owner/admin/member/solo and its account, billing, and
 * team-admin surfaces verified. Provisioned by {@see DemoTeamSeeder},
 * targeted by the `splicewire:beam:account-login-as` affordance. Development/preview only — never real
 * end-users.
 *
 * The roster is **derived from the {@see Role} enum**, not a hand-authored list: one
 * shared-team subject per role case (Owner is the team creator; the invitable roles join
 * as members), plus a `solo` subject that models the default team-of-one shape. Add a
 * `Role` case → a fully-provisioned demo subject appears in every satellite with no
 * seeder edit. This honours the enum's own contract ("everything that needs a role
 * vocabulary derives from these cases; there is no parallel list anywhere") — the old
 * hardcoded `SUBJECTS` map was exactly the parallel list the enum forbids.
 */
class Demo
{
    /**
     * key => {role, shared}. `shared` subjects sit together on one "Demo Team" (so the
     * role gates can be checked side by side); `solo` gets its own team-of-one (the
     * default satellite shape on registration). Role-derived; `solo` is the one hand-named
     * structural extra (a shape, not a role).
     *
     * @return array<string, array{role: Role, shared: bool}>
     */
    public static function subjects(): array
    {
        $subjects = [];

        foreach (Role::cases() as $role) {
            $subjects[$role->value] = ['role' => $role, 'shared' => true];
        }

        $subjects['solo'] = ['role' => Role::Owner, 'shared' => false];

        return $subjects;
    }

    /**
     * Are the demo affordances live? Explicit config wins; otherwise on everywhere but
     * production.
     */
    public static function enabled(): bool
    {
        $flag = config('beam-accounts.demo.enabled');

        if ($flag !== null) {
            return (bool) $flag;
        }

        return ! app()->environment('production');
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::subjects());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::subjects());
    }

    /** The role a demo subject holds on its team. */
    public static function roleFor(string $key): Role
    {
        return self::subjects()[$key]['role']
            ?? throw new InvalidArgumentException("Unknown demo subject [{$key}].");
    }

    /** Whether the subject sits on the shared "Demo Team" (vs its own team-of-one). */
    public static function isShared(string $key): bool
    {
        return self::subjects()[$key]['shared'] ?? false;
    }

    public static function email(string $key): string
    {
        $domain = config('beam-accounts.demo.email_domain', 'example.test');

        return "demo-{$key}@{$domain}";
    }

    public static function name(string $key): string
    {
        return 'Demo '.ucfirst($key);
    }
}
