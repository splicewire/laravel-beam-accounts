<?php

namespace Schemastud\Beam\Accounts\Enums;

/**
 * The single source of truth for the team roles the account runtime understands.
 *
 * Owner is provisioned on registration (team-of-one); admin/member come into play
 * with multi-member teams (issue 04). Everything that needs a role vocabulary —
 * spatie's team-scoped role sync, the invite context, the frame team editor, and
 * any emitted JSON Schema — derives from these cases. There is no parallel list
 * anywhere: the "which roles are invitable" and "which are assignable" nuances are
 * expressed as constraint methods over this one enum, not as second definitions.
 */
enum Role: string
{
    case Owner = 'owner';

    case Admin = 'admin';

    case Member = 'member';

    /**
     * The roles a team owner may assign to an existing member (every case —
     * ownership transfer included).
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return self::cases();
    }

    /**
     * The roles offerable in the invitation context. Owner is excluded: the owner
     * is the team creator, not something you invite someone in as (invite-excludes-owner
     * as a constraint over the one enum, never a separate list).
     *
     * @return array<int, self>
     */
    public static function invitable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $role !== self::Owner,
        ));
    }

    /**
     * The backing string values of every case — the canonical list spatie role sync,
     * validation rules, and schema/option consumers read from.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    /**
     * The backing string values offerable in the invitation context.
     *
     * @return array<int, string>
     */
    public static function invitableValues(): array
    {
        return array_map(fn (self $role) => $role->value, self::invitable());
    }

    /**
     * Human label for a case (title-cased value). The frame editor / any UI reads
     * this rather than hand-authoring labels.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Schema-derived projection of the enum: the shape a JSON Schema `enum` (and a
     * frame/option consumer) reads its cases from. This is the single declaration —
     * a Data class' generated schema, a role-manifest endpoint, and the TS editor all
     * read these rather than restating the vocabulary.
     *
     * @return array{enum: array<int, string>, options: array<int, array{value: string, label: string}>}
     */
    public static function schema(): array
    {
        return [
            'enum' => self::values(),
            'options' => self::options(),
        ];
    }

    /**
     * {value,label} option pairs for a select/editor. Derived from the cases; never
     * hand-authored.
     *
     * @param  array<int, self>|null  $cases  Restrict to a context subset (e.g. self::invitable()).
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(?array $cases = null): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            $cases ?? self::cases(),
        );
    }
}
