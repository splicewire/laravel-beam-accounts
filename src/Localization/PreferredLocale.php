<?php

namespace Splicewire\Beam\Accounts\Localization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Localization\Locales;

/**
 * A person's saved language, read and written on the CENTRAL identity only (localization 36; ruling 03 owner resolution
 * (a): `users.preferred_locale`, central-only).
 *
 * - **Read:** the central user's value, if it is still a supported locale, else null (automatic). For a tenant-side
 *   replica (a stancl Syncable with a central model), the central row is found by the synced global id, and it never
 *   reads a replica column.
 * - **Write:** only on an explicit choice ({@see save()}): a supported tag, or null to clear. A host that has not run
 *   `add_preferred_locale_to_users_table` yet is a no-op, never a query error.
 *
 * @internal The one place the central/replica rule lives; {@see PreferredLocaleSource}, {@see
 *   \Splicewire\Beam\Accounts\Concerns\HasPreferredLocale} and the profile writer use it.
 */
final class PreferredLocale
{
    public const COLUMN = 'preferred_locale';

    /** @var array<string, bool> connection name => whether its users table has the column */
    private static array $hasColumn = [];

    /** The person's saved, still-supported language, or null for automatic. */
    public static function of(mixed $person): ?string
    {
        $central = $person instanceof Model ? self::central($person) : null;

        return $central === null ? null : Locales::supportedTag(self::raw($central));
    }

    /**
     * Save an explicit choice on the central identity: a supported tag, or null to clear. Any other value is
     * refused (the caller validated it already; this is the last guard).
     */
    public static function save(Model $person, ?string $locale): void
    {
        $tag = $locale === null || trim($locale) === '' ? null : Locales::supportedTag($locale);
        if ($locale !== null && trim($locale) !== '' && $tag === null) {
            throw new \InvalidArgumentException("[{$locale}] is not a supported locale.");
        }
        $central = self::central($person);
        if ($central === null || ! self::hasColumn($central)) {
            return;
        }
        $central->forceFill([self::COLUMN => $tag])->save();
        if ($central !== $person && $person->getConnectionName() === $central->getConnectionName()) {
            $person->setAttribute(self::COLUMN, $tag);
        }
    }

    /** The central user known by this email, or null (an invitee who has no account yet). */
    public static function centralByEmail(string $email): ?Model
    {
        $model = BeamAccounts::userModel();

        return is_a($model, Model::class, true) ? $model::query()->where('email', $email)->first() : null;
    }

    /** The central identity behind `$person`: itself, unless it is a tenant-side replica. */
    public static function central(Model $person): ?Model
    {
        // A replica (stancl's Syncable, tenant side) names its central model and carries no tenant-model name; the
        // central User (a SyncMaster) does name one.
        if (method_exists($person, 'getCentralModelName') && ! method_exists($person, 'getTenantModelName')) {
            $centralModel = $person->getCentralModelName();
            $key = method_exists($person, 'getGlobalIdentifierKeyName') ? $person->getGlobalIdentifierKeyName() : 'global_id';
            $value = method_exists($person, 'getGlobalIdentifierKey') ? $person->getGlobalIdentifierKey() : $person->getAttribute($key);

            return $value === null ? null : $centralModel::query()->where($key, $value)->first();
        }

        return $person;
    }

    /**
     * The stored choice, read off the RAW attributes, never `getAttribute()` (range review B12-2; the TokenLastFour
     * precedent): under `Model::preventAccessingMissingAttributes()` a missing attribute throws, and "this host has not
     * migrated yet" must read as automatic. A row loaded through a `select()` that left the column out is fetched for
     * that one column, if its table has it, so a saved choice is never misread as automatic.
     */
    private static function raw(Model $central): ?string
    {
        $attributes = $central->getAttributes();
        if (array_key_exists(self::COLUMN, $attributes)) {
            $value = $attributes[self::COLUMN];
        } elseif ($central->exists && self::hasColumn($central)) {
            $value = $central->newQuery()->whereKey($central->getKey())->value(self::COLUMN);
        } else {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function hasColumn(Model $central): bool
    {
        $connection = (string) ($central->getConnectionName() ?? config('database.default'));

        return self::$hasColumn[$connection.'|'.$central->getTable()] ??= Schema::connection($central->getConnectionName())->hasColumn($central->getTable(), self::COLUMN);
    }

    /** Forget the memoized column checks (a test that migrates mid-run). */
    public static function flush(): void
    {
        self::$hasColumn = [];
    }
}
