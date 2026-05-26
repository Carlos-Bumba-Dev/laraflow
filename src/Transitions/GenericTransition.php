<?php

namespace LaraFlow\Workflow\Transitions;

use Spatie\ModelStates\Transition;
use Spatie\ModelStates\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaraFlow\Workflow\Events\WorkflowTransitioned;
use LaraFlow\Workflow\Contracts\TransitionGuard;
use LaraFlow\Workflow\Contracts\TransitionAction;
use InvalidArgumentException;
use RuntimeException;

class GenericTransition extends Transition
{
    /**
     * O Laravel e a biblioteca da Spatie injetam automaticamente o Model e o TargetState.
     * Os demais componentes são configurados diretamente no array do Estado Central.
     */
    public function __construct(
        protected Model $model,
        protected State $targetState,
        protected array $roles = [],
        protected array $permissions = [],
        protected array $dependencies = [],
        protected array $guards = [],
        protected array $actions = [],
        protected array $afterCommit = [],
        protected array $payload = [],
        protected bool $force = false // 🔑 Chave Mestra para intervenções de emergência
    ) {}

    /**
     * O ponto de entrada da transição executado pelo framework.
     */
    public function handle(): Model
    {
        // 1. Validação de Integridade: Toda transição forçada EXIGE uma justificativa audível
        if ($this->force && empty($this->payload['reason'])) {
            throw new InvalidArgumentException("Transições forçadas exigem uma justificativa obrigatória (payload.reason).");
        }

        // 2. Camada de Segurança Reativa: Ignora travas estáticas se o modo force estiver ativo
        if (!$this->force) {
            $this->validateDependencies();
            $this->validateAuthorization();
            $this->executeGuards();
        }

        $oldStateLivewire = null;
        $operatorUserId = auth()->id() ?? config('laraflow.compliance.system_user_id', 1);

        // 3. Operação Atômica com Bloqueio Pessimista contra Race Conditions
        DB::transaction(function () use (&$oldStateLivewire, $operatorUserId) {
            
            // Re-busca a linha aplicando Lock de Escrita para blindar concorrência
            $lockedModel = $this->model->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->model->getKey());

            // Double-Check de Concorrência (Garante integridade se o estado mudou na fila do Lock)
            if (get_class($lockedModel->status) !== get_class($this->model->status)) {
                throw new RuntimeException("Concorrência detectada: O estado deste registro foi alterado por outro processo paralelo.");
            }

            $oldStateLivewire = $lockedModel->status->toLivewire();

            // Seta o novo objeto de estado e persiste no banco
            $lockedModel->status = $this->targetState;
            $lockedModel->save();

            // Rastreabilidade Automática (Se o Model implementar auditoria através da Trait)
            if (method_exists($lockedModel, 'recordStatusHistory')) {
                // Passamos o $this->force para registrar na flag 'is_forced' da tabela de auditoria
                $lockedModel->recordStatusHistory(
                    $oldStateLivewire, 
                    $this->targetState->toLivewire(), 
                    $this->payload,
                    $operatorUserId,
                    $this->force
                );
            }

            // Executa as ações Síncronas utilizando a instância bloqueada (Se falharem, o banco sofre Rollback)
            $this->executeActions($this->actions, $lockedModel);

            // Atualização automática do SLA se o modelo suportar
            if (method_exists($lockedModel, 'updateSlaExpiration')) {
                $lockedModel->updateSlaExpiration();
            }
        });

        // Sincroniza a instância original presente na memória do Laravel
        $this->model->refresh();

        // 4. Efeitos Colaterais Pós-Sucesso (Event-Driven e AfterCommit fora da Transaction)
        DB::afterCommit(function () use ($oldStateLivewire, $operatorUserId) {
            
            // Dispara o evento nativo informando o ecossistema se a ação foi normal ou Chave Mestra
            event(new WorkflowTransitioned(
                model: $this->model,
                fromState: $oldStateLivewire,
                toState: $this->targetState->toLivewire(),
                payload: $this->payload,
                userId: $operatorUserId,
                isForced: $this->force
            ));

            // Executa as ações em fila pós-commit
            $this->executeActions($this->afterCommit, $this->model);
        });

        return $this->model;
    }

    /**
     * Componente Dependencies: Avalia o estado de models relacionados.
     */
    protected function validateDependencies(): void
    {
        foreach ($this->dependencies as $relationPath => $requiredStateClass) {
            $currentStateInstance = data_get($this->model, $relationPath);

            if (!$currentStateInstance || !($currentStateInstance instanceof $requiredStateClass)) {
                $stateLabel = method_exists($requiredStateClass, 'label') 
                    ? app($requiredStateClass)->label() 
                    : class_basename($requiredStateClass);

                abort(422, "Operação retida: O recurso relacionado '{$relationPath}' precisa estar no estado [{$stateLabel}].");
            }
        }
    }

    /**
     * Componentes Roles & Permissions: Blindagem de segurança (ACL).
     */
    protected function validateAuthorization(): void
    {
        $user = auth()->user();

        if (!empty($this->roles)) {
            if (!$user || !method_exists($user, 'hasAnyRole') || !$user->hasAnyRole($this->roles)) {
                abort(403, "Acesso negado: Esta transição é restrita aos papéis: " . implode(', ', $this->roles));
            }
        }

        if (!empty($this->permissions)) {
            if (Gate::deniesAny($this->permissions)) {
                abort(403, "Acesso negado: Você não possui as credenciais necessárias para mover este registro.");
            }
        }
    }

    /**
     * Componente Guards: Executa as travas e validações de regras de negócio complexas.
     */
    protected function executeGuards(): void
    {
        foreach ($this->guards as $guardClass) {
            $guard = app($guardClass);

            if (!$guard instanceof TransitionGuard) {
                throw new InvalidArgumentException("A classe {$guardClass} deve implementar a interface TransitionGuard.");
            }

            $guard->check($this->model, $this->payload);
        }
    }

    /**
     * Componentes Actions & AfterCommit: Execução em lote de efeitos colaterais.
     */
    protected function executeActions(array $actionClasses, Model $modelInstance): void
    {
        foreach ($actionClasses as $actionClass) {
            $action = app($actionClass);

            if (!$action instanceof TransitionAction) {
                throw new InvalidArgumentException("A classe {$actionClass} deve implementar a interface TransitionAction.");
            }

            $action->execute($modelInstance, $this->payload);
        }
    }
}