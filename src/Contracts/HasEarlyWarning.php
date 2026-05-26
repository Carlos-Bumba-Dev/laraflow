<?php

namespace LaraFlow\Workflow\Contracts;

interface HasEarlyWarning
{
    public function earlyWarningMinutesBeforeTimeout(): int;
    public function earlyWarningTransitionTo(): string; // Retorna a classe do Estado Alvo
}