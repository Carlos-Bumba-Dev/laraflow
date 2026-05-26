<?php

namespace SeuNome\Workflow\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use SeuNome\Workflow\Models\StatusHistory;
use SeuNome\Workflow\Models\WorkflowExtension;

trait HasStateHistory
{
    public function statusHistories(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'model');
    }

    /**
     * Relacionamento com as extensões de prazo concedidas.
     */
    public function workflowExtensions(): MorphMany
    {
        return $this->morphMany(WorkflowExtension::class, 'model');
    }

    /**
     * Concede uma extensão de prazo de forma auditável para o estado atual.
     */
    public function extendDeadline(int $minutes, string $reason): void
    {
        // Força a existência de um motivo plausível
        if (empty(trim($reason))) {
            abort(422, "É obrigatório fornecer uma justificativa para estender o prazo de SLA.");
        }

        $this->workflowExtensions()->create([
            'state' => get_class($this->status), // Captura o estado atual do Model de forma dinâmica
            'extended_minutes' => $minutes,
            'reason' => $reason,
            'user_id' => auth()->id() ?? 1, // ID do supervisor logado
        ]);
    }
}