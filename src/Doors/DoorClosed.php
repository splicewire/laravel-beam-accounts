<?php

namespace Splicewire\Beam\Accounts\Doors;

use RuntimeException;

/** A user was asked for through a door that does not admit them. Nothing was created. */
final class DoorClosed extends RuntimeException
{
    public static function for(Door $door, Admission $admission): self
    {
        return new self("The [{$door->value}] door does not admit this account: {$admission->reason}");
    }
}
