<?php

namespace Splicewire\Beam\Accounts\Keys;

use Illuminate\Database\Eloquent\Model;

/**
 * A personal access token's last four characters: stamped where the plaintext is in hand, read
 * wherever a token is listed (owner-review-20261102 K-28).
 *
 * ## Why the mint site, and only the mint site
 *
 * The plaintext exists once, in the mint response. The row keeps `hash('sha256', $plainText)`, so
 * a model hook, an observer or a later backfill has nothing to take four characters from. Every
 * mint path that wants its rows identifiable calls {@see self::attributesFor()} with the plaintext
 * it is about to return. Today that is beam-accounts' `ApiTokenController::mint()` and tower's
 * `Api\V1\ApiTokenController` (`store`, `rotate`), the two deliberate-token surfaces. Sign-in
 * sessions, federation grants and deterministic service keys do not stamp, so their rows read null
 * and the roster shows the id tail for them.
 *
 * ## Why every method asks whether the column exists
 *
 * The column comes from a publish-only ALTER stub, so at every host it is absent until that host
 * publishes the stub and migrates. A host that declares the auth estate `'absent'` never receives
 * the stub at all. A mint that wrote `last_four` unconditionally would turn token creation into a
 * 500 at each of those hosts the moment it updated this package. Asking first keeps the mint
 * independent of migration order: before the migration a token is minted without the field, and
 * after it the field is written. The question costs one schema lookup per mint or list, which these
 * low-traffic routes can afford.
 */
final class TokenLastFour
{
    public const COLUMN = 'last_four';

    /**
     * The last four characters of a plaintext. Sanctum's bearer is `{id}|{secret}`, so the same
     * four come off the bearer and off the bare secret.
     */
    public static function of(string $plainText): string
    {
        return substr($plainText, -4);
    }

    /**
     * The attribute to merge into the mint's write, or `[]` where `$token`'s table has no
     * `last_four` column yet.
     *
     * @return array<string, string>
     */
    public static function attributesFor(Model $token, string $plainText): array
    {
        return self::columnExists($token) ? [self::COLUMN => self::of($plainText)] : [];
    }

    /**
     * The column to add to a list projection's `select()`, or `[]` where the table has no
     * `last_four` column yet. Selecting a column that does not exist is a query error.
     *
     * @return list<string>
     */
    public static function columnsFor(Model $token): array
    {
        return self::columnExists($token) ? [self::COLUMN] : [];
    }

    /**
     * The stored last four, or null for a row minted before the column existed, for a host without
     * the column, and for a row loaded through a `select()` that left the column out.
     *
     * Read off the raw attributes, never `$token->last_four`: under
     * `Model::preventAccessingMissingAttributes()` a missing attribute throws, and "this host has
     * not migrated yet" must read as null, not as an exception.
     */
    public static function read(Model $token): ?string
    {
        $value = $token->getAttributes()[self::COLUMN] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Whether `$token`'s own table, on its own connection, carries the column. */
    private static function columnExists(Model $token): bool
    {
        return $token->getConnection()->getSchemaBuilder()->hasColumn($token->getTable(), self::COLUMN);
    }
}
