<?php

namespace LaraFlow\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TransitionGuard
{
    public function check(Model $model, array $payload): void;
}