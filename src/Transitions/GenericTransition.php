<?php

namespace LaraFlow\Transitions;

use Spatie\ModelStates\Transition;
use Spatie\ModelStates\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaraFlow\Events\WorkflowTransitioned;
use LaraFlow\Contracts\TransitionGuard;
use LaraFlow\Contracts\TransitionAction;
use LaraFlow\Exceptions\DependencyNotSatisfiedException;
use LaraFlow\Exceptions\UnauthorizedTransitionException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Motor central de transições do ecossistema LaraFlow.
 *
 * Orquestra o ciclo completo de uma mudança de estado:
 * autorização → validação → persistência atômica → efeitos pós-commit.
 *
 * Cada etapa é configurável via array declarativo no Estado Central, sem
 * necessidade de subclasses. O flag `$force` atua como Chave Mestra para
 * intervenções de emergência, bypassando guardas estáticas com rastreabilidade
 * compulsória.
 *
 * @see config('laraflow.concurrency')  Timeout do lock pessimista por sessão.
 * @see config('laraflow.compliance')   ID do operador de sistema para contextos CLI/Job.
 */
class GenericTransition extends Transition
{
    /**
     * Estado anterior serializado, capturado dentro da transaction e
     * propagado para o bloco afterCommit sem uso de referência mutável.
     */
    protected mixed $oldStateLivewire = null;

    /**
     * @param  Model   $model        Instância Eloquent que sofrerá a transição.
     * @param  State   $targetState  Estado destino resolvido pelo framework Spatie.
     * @param  array   $roles        Papéis (roles) que podem executar esta transição.
     * @param  array   $permissions  Permissões granulares exigidas individualmente via Gate.
     * @param  array   $dependencies Mapa de relacionamentos e seus estados requeridos.
     *                               Formato: ['relation.path' => StateClass::class]
     * @param  array   $guards       Classes que implementam {@see TransitionGuard}.
     * @param  array   $actions      Ações síncronas executadas dentro da transaction.
     *                               Classes que implementam {@see TransitionAction}.
     * @param  array   $afterCommit  Ações assíncronas disparadas após o commit.
     *                               Classes que implementam {@see TransitionAction}.
     * @param  array   $payload      Dados contextuais propagados para guards, actions e auditoria.
     * @param  bool    $force        Chave Mestra: bypassa guardas estáticas exigindo payload.reason.
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
        protected bool $force = false
    ) {}

    /**
     * Ponto de entrada da transição, executado automaticamente pelo framework Spatie.
     *
     * Pipeline de execução:
     *   1. Integridade do payload (force exige justificativa)
     *   2. Autorização e validação de regras de negócio (ignoradas em modo force)
     *   3. Persistência atômica com lock pessimista e double-check de concorrência
     *   4. Efeitos colaterais pós-commit (eventos e ações assíncronas)
     *
     * @return Model Instância atualizada e sincronizada com o banco.
     *
     * @throws InvalidArgumentException          Se `force = true` e `payload.reason` estiver ausente.
     * @throws RuntimeException                  Se concorrência for detectada no double-check do lock.
     * @throws DependencyNotSatisfiedException   Se um modelo relacionado não estiver no estado requerido.
     * @throws UnauthorizedTransitionException   Se o usuário não possuir roles ou permissions exigidas.
     */
    public function handle(): Model
    {
        // Transições forçadas exigem justificativa para garantir a rastreabilidade forense.
        if ($this->force && empty($this->payload['reason'])) {
            throw new InvalidArgumentException(
                "Transições forçadas exigem uma justificativa obrigatória em payload['reason']."
            );
        }

        // Em modo normal, a tríade de segurança é aplicada na ordem correta:
        // dependências → autorização → regras de negócio (guards).
        if (!$this->force) {
            $this->validateDependencies();
            $this->validateAuthorization();
            $this->executeGuards();
        }

        // Fallback para contextos sem sessão HTTP ativa (CLI, Jobs, SLA Cron).
        $operatorUserId = auth()->id() ?? config('laraflow.compliance.system_user_id');

        // Envolve a transaction com o timeout de lock pessimista, restaurando
        // o valor original da sessão ao final — seguro para pools de conexão
        // persistentes (Octane, Swoole, RoadRunner).
        $this->withinLockTimeout(function () use ($operatorUserId) {

            // Bloco atômico: qualquer falha aqui aciona rollback automático.
            DB::transaction(function () use ($operatorUserId) {

                // Lock de escrita garante que nenhuma outra requisição paralela altere
                // este registro enquanto a transição estiver em andamento.
                $lockedModel = $this->model->newQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->model->getKey());

                // Double-check: entre a validação inicial e a obtenção do lock, outro
                // processo pode ter alterado o estado. Nesse caso, abortamos com segurança.
                if (get_class($lockedModel->status) !== get_class($this->model->status)) {
                    throw new RuntimeException(
                        "Concorrência detectada: o estado deste registro foi alterado por outro processo paralelo."
                    );
                }

                // Captura em propriedade para evitar referência mutável via closure.
                $this->oldStateLivewire = $lockedModel->status->toLivewire();

                $lockedModel->status = $this->targetState;
                $lockedModel->save();

                // Registra a trilha forense completa se a trait de auditoria estiver presente.
                if (method_exists($lockedModel, 'recordStatusHistory')) {
                    $lockedModel->recordStatusHistory(
                        $this->oldStateLivewire,
                        $this->targetState->toLivewire(),
                        $this->payload,
                        $operatorUserId,
                        $this->force
                    );
                }

                // Ações síncronas rodam dentro da transaction: uma falha aqui
                // reverte toda a operação atomicamente.
                $this->executeActions($this->actions, $lockedModel);

                // Recalcula o cronômetro de SLA para o novo estado, se suportado.
                if (method_exists($lockedModel, 'updateSlaExpiration')) {
                    $lockedModel->updateSlaExpiration();
                }
            });
        });

        // Sincroniza a instância em memória com o estado persistido no banco.
        $this->model->refresh();

        // Efeitos pós-commit: rodam apenas após a confirmação definitiva no banco,
        // garantindo que eventos e filas nunca sejam disparados em caso de rollback.
        DB::afterCommit(function () use ($operatorUserId) {
            event(new WorkflowTransitioned(
                model:     $this->model,
                fromState: $this->oldStateLivewire,
                toState:   $this->targetState->toLivewire(),
                payload:   $this->payload,
                userId:    $operatorUserId,
                isForced:  $this->force
            ));

            $this->executeActions($this->afterCommit, $this->model);
        });

        return $this->model;
    }

    /**
     * Executa um callable dentro de um contexto com timeout de lock configurado,
     * restaurando o valor original da sessão ao final — independente de sucesso ou falha.
     *
     * Corrige o vazamento de estado em pools de conexão persistentes (Octane, Swoole):
     * sem restauração, o timeout setado numa transição contaminaria as próximas
     * requisições que reutilizarem a mesma conexão MySQL.
     *
     * Aplica apenas para MySQL/MariaDB via `innodb_lock_wait_timeout`. Para outros SGBDs,
     * sobrescreva este método na subclasse (ex.: PostgreSQL usa `lock_timeout`).
     *
     * @see config('laraflow.concurrency.timeout_seconds')
     */
    protected function withinLockTimeout(callable $callback): void
    {
        $timeout = config('laraflow.concurrency.timeout_seconds');

        if ($timeout === null) {
            $callback();
            return;
        }

        $original = DB::scalar("SELECT @@SESSION.innodb_lock_wait_timeout");
        DB::statement("SET SESSION innodb_lock_wait_timeout = {$timeout}");

        try {
            $callback();
        } finally {
            // O finally garante a restauração mesmo que a transaction lance uma exceção.
            DB::statement("SET SESSION innodb_lock_wait_timeout = {$original}");
        }
    }

    /**
     * Valida se os modelos relacionados estão nos estados requeridos para prosseguir.
     *
     * As dependências bloqueiam a transição quando um pré-requisito externo ainda
     * não foi satisfeito — ex.: uma proposta só pode ser aprovada se o cliente
     * já estiver com cadastro homologado.
     *
     * Lança uma exceção de domínio, não HTTP, para garantir que esta classe
     * seja utilizável em contextos CLI, Jobs e automações de SLA.
     *
     * @throws DependencyNotSatisfiedException
     */
    protected function validateDependencies(): void
    {
        foreach ($this->dependencies as $relationPath => $requiredStateClass) {
            $currentStateInstance = data_get($this->model, $relationPath);

            if (!$currentStateInstance || !($currentStateInstance instanceof $requiredStateClass)) {
                $stateLabel = method_exists($requiredStateClass, 'label')
                    ? app($requiredStateClass)->label()
                    : class_basename($requiredStateClass);

                throw new DependencyNotSatisfiedException(
                    "Operação retida: '{$relationPath}' precisa estar no estado [{$stateLabel}]."
                );
            }
        }
    }

    /**
     * Valida roles e permissions do usuário autenticado via Spatie Permission e Gate.
     *
     * A verificação de roles é executada primeiro por ser mais barata (cache em memória).
     * As permissions são verificadas individualmente via `Gate::denies()` — o nativo
     * do Laravel — sem depender de macros ou métodos inexistentes.
     *
     * Lança uma exceção de domínio, não HTTP, para garantir que esta classe
     * seja utilizável em contextos CLI, Jobs e automações de SLA.
     *
     * @throws UnauthorizedTransitionException
     */
    protected function validateAuthorization(): void
    {
        $user = auth()->user();

        if (!empty($this->roles)) {
            if (!$user || !method_exists($user, 'hasAnyRole') || !$user->hasAnyRole($this->roles)) {
                throw new UnauthorizedTransitionException(
                    "Acesso negado: transição restrita aos papéis: " . implode(', ', $this->roles)
                );
            }
        }

        foreach ($this->permissions as $permission) {
            if (Gate::denies($permission)) {
                throw new UnauthorizedTransitionException(
                    "Acesso negado: a permissão [{$permission}] é necessária para mover este registro."
                );
            }
        }
    }

    /**
     * Executa os guards de regra de negócio registrados para esta transição.
     *
     * Guards encapsulam validações complexas que vão além de autorização — ex.: verificar
     * se um documento foi assinado ou se um limite de crédito está disponível. Cada guard
     * deve lançar uma exceção para interromper o fluxo; retornar void significa aprovação.
     *
     * @throws InvalidArgumentException Se a classe não implementar {@see TransitionGuard}.
     */
    protected function executeGuards(): void
    {
        foreach ($this->guards as $guardClass) {
            $guard = app($guardClass);

            if (!$guard instanceof TransitionGuard) {
                throw new InvalidArgumentException(
                    "{$guardClass} deve implementar a interface TransitionGuard."
                );
            }

            $guard->check($this->model, $this->payload);
        }
    }

    /**
     * Executa um lote de actions sobre o modelo, injetando dependências via container.
     *
     * Utilizado tanto para ações síncronas (dentro da transaction) quanto para ações
     * assíncronas (afterCommit). A instância do model é passada explicitamente para
     * garantir que actions síncronas operem sobre o model já bloqueado.
     *
     * @param  array  $actionClasses Classes que implementam {@see TransitionAction}.
     * @param  Model  $modelInstance Instância do model a ser passada para cada action.
     *
     * @throws InvalidArgumentException Se a classe não implementar {@see TransitionAction}.
     */
    protected function executeActions(array $actionClasses, Model $modelInstance): void
    {
        foreach ($actionClasses as $actionClass) {
            $action = app($actionClass);

            if (!$action instanceof TransitionAction) {
                throw new InvalidArgumentException(
                    "{$actionClass} deve implementar a interface TransitionAction."
                );
            }

            $action->execute($modelInstance, $this->payload);
        }
    }
}