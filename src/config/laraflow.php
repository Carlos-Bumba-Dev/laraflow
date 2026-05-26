<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modelos Monitorados pelo Ecossistema (Workflow Models)
    |--------------------------------------------------------------------------
    |
    | Registre aqui o NameSpace completo de todos os modelos Eloquent que utilizam
    | a trait `HasStateHistory` e participam da gestão de SLAs e transições.
    | O comando `workflow:check-slas` utilizará esta lista como o escopo
    | principal de varredura.
    |
    */
    'monitored_models' => [
        // App\Models\Proposta::class,
        // App\Models\Reclamacao::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configurações de Concorrência e Isolamento (Locks)
    |--------------------------------------------------------------------------
    |
    | Controla o comportamento do Pessimistic Locking no motor de transição.
    |
    | 'timeout_seconds': Tempo máximo que uma transição concorrente esperará
    |                    pelo Lock antes de estourar uma exceção no banco.
    |
    */
    'concurrency' => [
        'timeout_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Governança, Compliance e Auditoria
    |--------------------------------------------------------------------------
    |
    | Configurações estritas para manter a integridade da trilha forense.
    |
    | 'system_user_id': ID do usuário utilizado como fallback quando uma transição
    |                   é disparada via Console/CLI ou Jobs em Background onde
    |                   não há uma sessão HTTP ativa (auth()->id() é nulo).
    |
    */
    'compliance' => [
        'system_user_id' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gerenciamento Global de SLAs e Alertas
    |--------------------------------------------------------------------------
    |
    | Define as tabelas e parâmetros padrão para o monitoramento do cronômetro.
    |
    | 'extensions_table': Nome da tabela física que armazena as dilações de prazo
    |                     concedidas aos processos em andamento.
    |
    */
    'sla' => [
        'extensions_table' => 'workflow_extensions',
    ],

];