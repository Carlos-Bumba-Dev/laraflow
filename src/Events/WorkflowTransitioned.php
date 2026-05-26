<?php

namespace LaraFlow\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model $model,
        public ?string $fromState,
        public string $toState,
        public array $payload,
        public int|string|null $userId,
        public bool $isForced = false
    ) {}
}