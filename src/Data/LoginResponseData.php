<?php

namespace Splicewire\Beam\Accounts\Data;

use Splicewire\Beam\Data\BeamData;

class LoginResponseData extends BeamData
{
    public function __construct(
        public ?AuthUserData $data,
        public bool $success = true,
        public ?string $message = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public ?int $total = null,
    ) {}
}
