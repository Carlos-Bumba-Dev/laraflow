<?php

namespace LaraFlow\Workflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LaraFlow\Workflow\Contracts\HasSla;
use LaraFlow\Workflow\Contracts\HasEarlyWarning;
use LaraFlow\Workflow\Events\SlaBreached;
use Carbon\Carbon;

class WorkflowCheckSlasCommand extends Command
{
    protected $signature = 'workflow:check-slas {--model= : Filtrar varredura por um model específico}';
    protected $description = 'Varre de forma proativa o ecossistema aplicando ações preventivas e compulsórias de SLA';

    public function handle(): int
    {
        $this->info("Iniciando varredura e higienização de SLAs corporativos...");

        $extensionsTable = config('laraflow.sla.extensions_table');

        $monitoredModels = $this->option('model')
            ? [$this->option('model')]
            : $this->getRegisteredWorkflowModels();

        foreach ($monitoredModels as $modelClass) {
            if (!method_exists($modelClass, 'statusHistories')) {
                continue;
            }

            $this->comment("Varrendo instâncias ativas de: {$modelClass}");

            $modelClass::query()->chunk(100, function ($models) use ($modelClass, $extensionsTable) {
                foreach ($models as $model) {

                    $stateInstance = $model->status;
                    $stateClass = get_class($stateInstance);

                    if (!$stateInstance instanceof HasSla) {
                        continue;
                    }

                    $latestTransition = $model->statusHistories()
                        ->where('to_state', $stateClass)
                        ->latest('id')
                        ->first();

                    if (!$latestTransition) {
                        continue;
                    }

                    $entryTime = Carbon::parse($latestTransition->created_at);
                    $minutesSpent = now()->diffInMinutes($entryTime);

                    $baseMinutes = $stateInstance->slaTimeoutInMinutes();
                    $totalExtensions = DB::table($extensionsTable)
                        ->where('model_type', $modelClass)
                        ->where('model_id', $model->id)
                        ->where('state', $stateClass)
                        ->sum('extended_minutes');

                    $slaTimeout = $baseMinutes + $totalExtensions;
                    $id = $model->getKey();

                    DB::transaction(function () use ($model, $stateInstance, $stateClass, $minutesSpent, $slaTimeout, $id) {

                        $lockedModel = $model->newQuery()->lockForUpdate()->find($id);

                        if (!$lockedModel || get_class($lockedModel->status) !== $stateClass) {
                            return;
                        }

                        // =========================================================================
                        // CAMADA 1: PRIORIDADE ABSOLUTA - O prazo estourou completamente?
                        // =========================================================================
                        if ($minutesSpent >= $slaTimeout) {
                            $this->warn("🚨 ID #{$id} estourou o prazo máximo ({$minutesSpent}/{$slaTimeout} min). Movendo para fallback final.");

                            $minutesOverdue = $minutesSpent - $slaTimeout;
                            event(new SlaBreached($lockedModel, $stateClass, $minutesOverdue));

                            $lockedModel->status->transitionTo($stateInstance->autoTransitionTo(), [
                                'reason' => "Transição compulsória automática: Violação crítica de SLA excedida por {$minutesOverdue} minutos."
                            ]);

                            return;
                        }

                        // =========================================================================
                        // CAMADA 2: JANELA PREVENTIVA - Zona Amarela (Early Warning)
                        // =========================================================================
                        if (
                            $stateInstance instanceof HasEarlyWarning &&
                            $minutesSpent >= ($slaTimeout - $stateInstance->earlyWarningMinutesBeforeTimeout())
                        ) {
                            $this->info("⚠️ ID #{$id} entrou na janela de alerta ({$minutesSpent}/{$slaTimeout} min). Disparando ação proativa.");

                            $lockedModel->status->transitionTo($stateInstance->earlyWarningTransitionTo(), [
                                'reason' => 'Transição preventiva automática: Registro atingiu a zona amarela de proximidade do limite de SLA.'
                            ]);
                        }
                    });
                }
            });
        }

        $this->info("Varredura e mitigação de SLAs concluída.");
        return 1;
    }

    protected function getRegisteredWorkflowModels(): array
    {
        return config('laraflow.monitored_models', []);
    }
}