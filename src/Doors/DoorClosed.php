<?php

namespace Splicewire\Beam\Accounts\Doors;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** A user was asked for through a door that does not admit them. Nothing was created. */
final class DoorClosed extends RuntimeException
{
    public static function for(Door $door, Admission $admission): self
    {
        return new self("The [{$door->value}] door does not admit this account: {$admission->reason}");
    }

    /** Over HTTP a closed door is not found, never a 500 (rule BUY-9). */
    public function render(): Response
    {
        return response('', 404);
    }
}
