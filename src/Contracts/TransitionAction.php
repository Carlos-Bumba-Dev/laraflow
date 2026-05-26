<?php

namespace LaraFlow\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TransitionAction
{
    public function execute(Model $model, array $payload): void;
}