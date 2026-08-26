<?php

namespace Remonode\SDK\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RemonodePaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly array $data) {}
}
