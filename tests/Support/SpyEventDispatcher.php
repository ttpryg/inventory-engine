<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Tests\Support;

use Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface;

class SpyEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function dispatched(string $eventClass): bool
    {
        foreach ($this->events as $event) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }

        return false;
    }

    public function countOf(string $eventClass): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            if ($event instanceof $eventClass) {
                $count++;
            }
        }

        return $count;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
