<?php

namespace Remonode\SDK\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RemonodeSubscriptionUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventType,
        public readonly array $data,
    ) {}
}
