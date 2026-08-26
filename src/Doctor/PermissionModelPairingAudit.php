<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\Models\Role as BeamAccountsRole;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;
use Splicewire\Beam\Doctor\Support\SchemaKeyIndex;

/**
 * **The permission schema and the model that keys it are one decision, and this package ships both
 * halves while binding neither.** The pairing check for `create_permission_tables`.
 *
 * ## The pair, and why nothing binds it
 * beam-accounts publishes `database/migrations/shared/create_permission_tables.php.stub` with
 * `roles.id`/`permissions.id` as `uuid(...)->primary()` — argued at length in that stub's own docblock,
 * because `model_has_roles.model_id` is JOINed against the holder's key and Postgres has no implicit
 * `uuid ↔ varchar` cast. It also ships {@see BeamAccountsRole} and its Permission twin, six-line
 * subclasses whose only job is `HasUuids`. It **deliberately binds neither** to
 * `config('permission.models.role')` — beam-facade ticket 98's ruling, guarded by a test that asserts
 * the provider never starts doing so, because a package-level default would push uuid strings at the
 * live bigint keys of the hosts still on the integer schema.
 *
 * That refusal stands. Its consequence is what this audit exists for: **the pairing became a host's
 * job and nothing in the estate stated it, checked it, or failed on it** (beam-facade ticket 141).
 *
 * ## The failure, and why it lands on the primary path rather than a corner
 * {@see TeamProvisioner::syncSpatieRole()} resolves `app(config('permission.models.role'))::findOrCreate(...)`.
 * Team-of-one provisioning runs that at **user registration**. So a host whose `roles` table is keyed
 * by uuid while its bound Role model is stock Spatie — a plain auto-incrementing Eloquent model that
 * generates no identifier on create — does not degrade a feature. It returns a 500 on signup, with
 * `null value in column "id" of relation "roles"` (Postgres) or `NOT NULL constraint failed: roles.id`
 * (SQLite). Measured: converging only beam-accounts' own test fixture to the shipped uuid shape turned
 * 236 green tests into 27 failures, every one of them on that single cause.
 *
 * ## Predicates
 *  - **`unpaired-uuid-publish`** — the host has published a `create_permission_tables` that keys `roles`
 *    by uuid/ulid, and `config('permission.models.role')` resolves to a class that generates no key.
 *    This is the fatal state. It is reported as a **fail**, not the advisory warn the estate's
 *    conformance checks default to, because unlike a style drift or a foreign key a real database will
 *    later reject, this one is already breaking the package's primary path on this host today.
 *  - **`unpaired-integer-publish`** — the converse and, at time of writing, the whole live population:
 *    the host publishes the **integer** permission schema and binds an integer-keyed model, which is
 *    self-consistent and works. Reported anyway, at warn, for the reason the next section gives.
 *  - **`pin-contradicted`** — the host declared a pin under `beam.accounts.permissions.key_type` and
 *    the published schema does not match it. A declaration that has gone stale is worse than none,
 *    because it is the thing a later session will trust instead of measuring.
 *
 * ## Why a working, self-consistent integer host is still reported
 * Because **the repair the estate prescribes for it is the act that breaks it**, and the prescription
 * is written down while the hazard is not.
 *
 * AGENTS.md's stale-snapshot rule tells a session that finds a divergent published migration to compare
 * the host's copy against the package's own `database/migrations` stub of the same stem, **basename
 * match, timestamp prefix stripped**, and republish. That rule is sound and it is ambiguous here, for a
 * reason measured rather than supposed: `spatie/laravel-permission` ships
 * `create_permission_tables.php.stub` and so does beam-accounts, **identical stem**. At
 * `~/Herd/audiostud`, `~/Herd/fable` and `~/Herd/numero` the published copy is *Spatie's*, byte-compared
 * — it carries no `ConvergentTable` import and keeps Spatie's inline comments — so a basename match
 * identifies it as a stale beam-accounts snapshot, and the prescribed republish silently converts a
 * working host into the `unpaired-uuid-publish` state above. **The estate is safe today by sequencing
 * luck: the hosts on the integer publish are exactly the hosts on the stock binding, so the wrong
 * pairing has never been built. Republishing builds it.**
 *
 * So the finding's whole content is: *republishing beam-accounts' permission stub here is a two-file
 * atomic change — the migration and `config/permission.php`'s `models.role`/`models.permission` — and
 * doing one without the other breaks signup.* A host that has decided to stay on the integer schema
 * says so in config and the finding goes away, which is item 2 of ticket 141: an exemption declared
 * where a later reader can find it, rather than implied by silence.
 *
 * ## What this does NOT duplicate
 * {@see KeyTypeConformanceAudit} in `splicewire/laravel-beam` already carries a `third-party-key-binding`
 * predicate naming `roles`/`permissions` and `permission.models.role`, and it fires on the same fatal
 * state. The overlap is deliberate and the division is a **tier** fact, not a preference:
 *
 *  - beam sits **below** beam-accounts (beam-accounts requires it), so beam may not name
 *    `Splicewire\Beam\Accounts\Models\Role`. Its remedy line is necessarily generic — *"point it at a
 *    first-party model that uses `HasUuids`"* — and it cannot mention `TeamProvisioner`, the signup
 *    path, or the stub whose publication creates the hazard.
 *  - beam-accounts sits above and may name all of it, including its own shipped pair by fully-qualified
 *    name, which is the one thing an operator reading the finding actually needs.
 *
 * And beam's predicate is **structurally blind to the integer half**: it skips a table declared `int`,
 * correctly, because from beam's vantage an integer `roles` keyed by an integer model is simply a
 * consistent schema. Only the package that ships the uuid stub knows that consistency is a pin awaiting
 * a republish. That half — the live population — is this audit's alone.
 *
 * ## Honesty about reach
 * Static, over source and config: it reads the host's `database/migrations/**`, resolves the bound class
 * through `config()`, and reflects on it. **No database connection**, so it runs at a host whose database
 * is unreachable and before a single migration has been applied — deliberate, because two of the three
 * live-population hosts are exactly the hosts an agent reaches for a census when a boot is unavailable.
 * The cost is that a `roles` table created by a migration **outside** the host's own
 * `database/migrations/` — a package-loaded migration, or tower's test fixtures — is a table this audit
 * cannot see, and it says so in the Pass line rather than reporting green.
 */
class PermissionModelPairingAudit implements DoctorAudit
{
    public const CHECK = 'beam.accounts.permission-model-pairing';

    /** The pair this package ships, named in every remedy line. */
    public const SHIPPED_ROLE = 'Splicewire\Beam\Accounts\Models\Role';

    public const SHIPPED_PERMISSION = 'Splicewire\Beam\Accounts\Models\Permission';

    /**
     * `table => [config key, the class the vendor package binds when the host overrides nothing]`.
     *
     * Both entries are checked. `permissions` is not decoration: a host that rebinds Role and forgets
     * Permission gets the identical NOT NULL failure one call later, on the first permission the
     * package provisions rather than the first role.
     *
     * @var array<string, array{config: string, vendor: string, shipped: string}>
     */
    public const PAIRS = [
        'roles' => [
            'config' => 'permission.models.role',
            'vendor' => 'Spatie\Permission\Models\Role',
            'shipped' => self::SHIPPED_ROLE,
        ],
        'permissions' => [
            'config' => 'permission.models.permission',
            'vendor' => 'Spatie\Permission\Models\Permission',
            'shipped' => self::SHIPPED_PERMISSION,
        ],
    ];

    /**
     * @param  string|null  $migrationsPath  the host's migrations root; null resolves `database_path('migrations')`
     * @param  string|null  $declaredPin  `'uuid'`, `'ulid'`, `'int'`, or null for "undeclared"
     */
    public function __construct(
        protected ?string $migrationsPath = null,
        protected ?string $declaredPin = null,
    ) {}

    public static function forApp(): self
    {
        $pin = null;

        try {
            $configured = function_exists('config') ? config('beam.accounts.permissions.key_type') : null;
            $pin = is_string($configured) && $configured !== '' ? $configured : null;
        } catch (\Throwable) {
            $pin = null;
        }

        $path = null;

        try {
            $path = function_exists('database_path') ? database_path('migrations') : null;
        } catch (\Throwable) {
            $path = null;
        }

        return new self($path, $pin);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $file = $this->publishedMigration();

        if ($file === null) {
            return [Finding::pass(self::CHECK, sprintf(
                'No `create_permission_tables` is published under %s, so this host does not own the '
                .'permission schema and there is no pairing here to check. Note this is a real blind '
                .'spot rather than a clean bill: a `roles` table created by a package-loaded migration '
                .'or a test fixture is invisible to a static, connection-free check.',
                $this->migrationsRoot(),
            ))];
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            return [Finding::warn(self::CHECK, sprintf('Could not read the published permission migration at %s.', $file))];
        }

        $findings = [];

        foreach (self::PAIRS as $table => $pair) {
            $declared = $this->declaredKeyType($source, $table);

            if ($declared === null) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '`%s` is created by %s but its primary key could not be read statically, so the '
                    .'pairing with `%s` is unverified. Not a pass — "could not look" and "nothing wrong" '
                    .'are different facts.',
                    $table,
                    basename($file),
                    $pair['config'],
                ));

                continue;
            }

            $bound = $this->boundClass($pair);
            $modelType = SchemaKeyIndex::keyTypeOfClass($bound);

            if ($this->declaredPin !== null && $this->declaredPin !== $declared) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    'This host declares `beam.accounts.permissions.key_type = %s`, but %s creates `%s` '
                    .'with a %s primary key. The declaration is what a later session will trust instead '
                    .'of measuring, so a stale one is worse than none — correct whichever half is wrong.',
                    $this->declaredPin,
                    basename($file),
                    $table,
                    $declared,
                ));

                continue;
            }

            if ($modelType === null) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '`%s` is published with a %s primary key, and `%s` names `%s`, which this host cannot '
                    .'load. The pairing is unverified rather than sound.',
                    $table,
                    $declared,
                    $pair['config'],
                    $bound,
                ));

                continue;
            }

            if ($declared !== 'int' && $modelType === 'int') {
                $findings[] = Finding::fail(self::CHECK, sprintf(
                    '`%s` is published with a %s primary key (%s), but `%s` binds `%s`, which generates no '
                    .'identifier on create. `TeamProvisioner::syncSpatieRole()` calls '
                    .'`app(config(\'%s\'))::findOrCreate(...)` during team-of-one provisioning at user '
                    .'registration, so this is a 500 on signup — `null value in column "id" of relation '
                    .'"%s"` — not a degraded feature. Set `%s` to `%s`, which this package ships for '
                    .'exactly this and does not bind by default (beam-facade ticket 98).',
                    $table,
                    $declared,
                    basename($file),
                    $pair['config'],
                    $bound,
                    $pair['config'],
                    $table,
                    $pair['config'],
                    $pair['shipped'],
                ));

                continue;
            }

            if ($declared === 'int' && $modelType === 'int' && $this->declaredPin === null) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '`%s` is published with an integer primary key (%s) and `%s` binds `%s`, which agrees '
                    .'— this host works today. It is reported because the repair the estate prescribes '
                    .'for it is the act that breaks it. beam-accounts ships this table keyed by uuid, and '
                    .'AGENTS.md\'s stale-snapshot rule matches a published copy to a package stub by '
                    .'BASENAME — but `spatie/laravel-permission` ships `create_permission_tables.php.stub` '
                    .'under the identical stem, so that match cannot tell the two apart and will read this '
                    .'working copy as stale beam-accounts. Republishing here is a TWO-FILE ATOMIC CHANGE: '
                    .'the migration AND `config/permission.php`\'s `models.role`/`models.permission` '
                    .'(point them at `%s` / `%s`). Doing one without the other is the signup 500 above. '
                    .'If this host is deliberately staying on the integer schema, declare it — set '
                    .'`beam.accounts.permissions.key_type` to `int` — and this finding goes away.',
                    $table,
                    basename($file),
                    $pair['config'],
                    $bound,
                    self::SHIPPED_ROLE,
                    self::SHIPPED_PERMISSION,
                ));

                continue;
            }

            if ($declared === 'int' && $modelType !== 'int') {
                $findings[] = Finding::fail(self::CHECK, sprintf(
                    '`%s` is published with an integer primary key (%s), but `%s` binds `%s`, which '
                    .'generates a %s. This is a half-done migration off the integer schema pointed the '
                    .'other way: the model will push a %s string at a bigint column. Move both halves or '
                    .'neither.',
                    $table,
                    basename($file),
                    $pair['config'],
                    $bound,
                    $modelType,
                    $modelType,
                ));
            }
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'The permission schema and its bound models agree (%s; %s).',
                basename($file),
                $this->declaredPin === null
                    ? 'no key-type pin declared'
                    : sprintf('pinned to %s by `beam.accounts.permissions.key_type`', $this->declaredPin),
            ))];
        }

        return $findings;
    }

    /**
     * The host's published permission-tables migration, or null.
     *
     * Matched by stem with the publish timestamp stripped, over the whole tree — the estate publishes
     * shared tables under `database/migrations/shared/` as often as at the root, and a check that only
     * looked at the root would report the blind-spot Pass at the hosts that follow the convention.
     */
    protected function publishedMigration(): ?string
    {
        $root = $this->migrationsRoot();

        if (! is_dir($root)) {
            return null;
        }

        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }

            $basename = $entry->getBasename();

            if ($basename === 'create_permission_tables.php' || str_ends_with($basename, '_create_permission_tables.php')) {
                $matches[] = $entry->getPathname();
            }
        }

        sort($matches);

        return $matches[0] ?? null;
    }

    protected function migrationsRoot(): string
    {
        return $this->migrationsPath ?? 'database/migrations';
    }

    /**
     * The primary-key type a migration declares for one permission table.
     *
     * Reads the table's creation block and takes the first key declaration in it.
     *
     * **Two creators, not one.** Spatie's stub and every host publish of it write
     * `Schema::create($tableNames['roles'], ...)`; beam-accounts' current stub writes
     * `ConvergentTable::named($tableNames['roles'])->create(...)` — the convergent guard the estate
     * adopted for migrations that must be safe to re-run over a partially-built schema. Both are in the
     * live population right now (`~/Herd/numero` is the first, `~/Herd/tower` the second), and a check
     * that knew only `Schema::create` reported "could not read this statically" at exactly the hosts
     * that are wired correctly — which is the worst possible place for a check to go quiet.
     *
     * The table is addressed through `$tableNames['roles']` rather than a literal in every copy the
     * estate has, so that subscript is what locates the block; the creator in front of it is what
     * varies.
     */
    protected function declaredKeyType(string $source, string $table): ?string
    {
        $creators = $this->creatorOffsets($source);
        $start = $creators[$table] ?? null;

        if ($start === null) {
            return null;
        }

        // Bounded by the next creator of ANY table, so a later table's declaration cannot be read as
        // this one's — the pivots that follow `roles` declare keys of their own.
        $later = array_filter($creators, static fn (int $offset): bool => $offset > $start);
        $next = $later === [] ? null : min($later);
        $block = $next === null ? substr($source, $start) : substr($source, $start, $next - $start);

        if (preg_match("/\\\$table->(uuid|ulid|bigIncrements|increments|id)\\s*\\(\\s*(?:'id')?\\s*\\)/", $block, $m) !== 1) {
            return null;
        }

        return match ($m[1]) {
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            default => 'int',
        };
    }

    /**
     * Every table this migration CREATES, mapped to the byte offset where its creation starts.
     *
     * Only the two creators count. `Schema::table(...)`, `Schema::hasColumn(...)` and `Schema::drop(...)`
     * all take the same `$tableNames[...]` subscript and appear later in these files — including inside
     * the teams-column backfill that runs after every create — so matching the subscript alone would put
     * a block boundary in the middle of one.
     *
     * @return array<string, int>
     */
    protected function creatorOffsets(string $source): array
    {
        $pattern = "/(?:Schema::create|ConvergentTable::named)\\s*\\(\\s*\\\$tableNames\\['([a-z_]+)'\\]/";

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $offsets = [];

        foreach ($matches[1] as $index => $capture) {
            // First creation wins: a table created twice is a defect this audit does not own.
            $offsets[$capture[0]] ??= $matches[0][$index][1];
        }

        return $offsets;
    }

    /**
     * The class actually bound for a pair: the host's config override where there is one, else the
     * vendor package's own default.
     *
     * Guarded rather than assumed — this audit is unit-driven with no application bound, and `config()`
     * throws rather than returning null when the container is absent.
     *
     * @param  array{config: string, vendor: string, shipped: string}  $pair
     */
    protected function boundClass(array $pair): string
    {
        try {
            $configured = function_exists('config') ? config($pair['config']) : null;
        } catch (\Throwable) {
            $configured = null;
        }

        return is_string($configured) && $configured !== '' ? $configured : $pair['vendor'];
    }
}
