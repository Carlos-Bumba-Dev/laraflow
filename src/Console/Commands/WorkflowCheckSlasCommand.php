<?php

namespace SeuNome\Workflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SeuNome\Workflow\Contracts\HasSla;
use SeuNome\Workflow\Events\SlaBreached;

class WorkflowCheckSlasCommand extends Command
{
    protected $signature = 'workflow:check-slas';
    protected $description = 'Varre o ecossistema em busca de registros com status estagnados fora do SLA';

    public function handle(): int
    {
        $this->info("Iniciando varredura de SLAs corporativos...");

        // 1. Busca os últimos registros de transição de status de forma atômica
        // aglutinando pelo par de colunas polimórficas (model_type e model_id)
        $latestTransitions = DB::table('status_histories')
            ->select('model_type', 'model_id', 'to_state', 'created_at')
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('status_histories')
                    ->groupBy('model_type', 'model_id');
            })
            ->get();

        foreach ($latestTransitions as $transition) {
            $stateClass = $transition->to_state;

            // Se o estado atual não implementa a interface de SLA, ignore-o imediatamente
            if (!is_subclass_of($stateClass, HasSla::class)) {
                continue;
            }

            // Instancia o estado de forma leve para ler o timeout configurado
            $stateInstance = app($stateClass);
            $allowedMinutes = $stateInstance->slaTimeoutInMinutes();

            $entryTime = \Carbon\Carbon::parse($transition->created_at);
            $minutesSpent = now()->diffInMinutes($entryTime);

            // Se o tempo gasto na fase for maior que o permitido, temos um estouro!
            if ($minutesSpent > $allowedMinutes) {
                $minutesOverdue = $minutesSpent - $allowedMinutes;

                // Resgata o Model real de forma dinâmica usando o polimorfismo do Eloquent
                $model = $transition->model_type::find($transition->model_id);

                if ($model && $model->status->toLivewire() === $stateInstance->toLivewire()) {
                    $this->warn("⚠️ VIOLAÇÃO: [{$transition->model_type}] ID #{$transition->model_id} está travado em [{$stateInstance->label()}] por {$minutesOverdue} minutos além do SLA.");
                    
                    // Dispara o evento para o ecossistema (notificar gerentes, Webhooks, Slack, etc.)
                    event(new SlaBreached($model, $stateClass, $minutesOverdue));
                }
            }
        }

        $this->info("Varredura concluída com sucesso.");
        return Command::SUCCESS;
    }
}