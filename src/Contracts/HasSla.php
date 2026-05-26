<?php

namespace LaraFlow\Workflow\Contracts;

interface HasSla
{
    public function slaTimeoutInMinutes(): int;
    public function autoTransitionTo(): string; // Estado de fallback compulsório
}