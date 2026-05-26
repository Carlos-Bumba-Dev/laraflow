<?php

namespace SeuNome\Workflow\Contracts;

interface HasSla
{
    /**
     * Define o tempo máximo tolerado neste estado (em minutos).
     */
    public function slaTimeoutInMinutes(): int;
}