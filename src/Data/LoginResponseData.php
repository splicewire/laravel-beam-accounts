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
        /**
         * `landing`: where the signed-in user lands, from {@see \Splicewire\Beam\Accounts\Landing} (ux-walkthrough
         * UX-11, IA-5). Absent on a refused sign-in.
         *
         * @var array{landing?: string}
         */
        public array $meta = [],
    ) {}
}
