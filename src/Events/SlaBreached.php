<?php

namespace LaraFlow\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;

class SlaBreached
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public string $stateClass,
        public int $minutesOverdue
    ) {}
}