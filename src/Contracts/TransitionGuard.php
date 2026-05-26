<?php

namespace LaraFlow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TransitionGuard
{
    public function check(Model $model, array $payload): void;
}