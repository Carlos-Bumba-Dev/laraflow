# LaraFlow 🌊

[![Latest Stable Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/Carlos-Bumba-Dev/laraflow)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE.md)
[![Laravel Core](https://img.shields.io/badge/laravel-10.x%20%2F%2011.x-red.svg)](https://laravel.com)
[![Engine Type](https://img.shields.io/badge/architecture-Enterprise%20%2F%20ACID-orange.svg)]()

O **LaraFlow** é um motor robusto de orquestração de processos e máquinas de estado (*State Machines*) de alta performance para o ecossistema Laravel. Construído sob as fundações do `spatie/laravel-model-states`, ele estende o framework para fornecer **consistência transacional estrita (ACID)**, trilha de auditoria imutável, gerenciamento automatizado de SLA e arquitetura orientada a eventos (EDA).

Foi projetado especificamente para sistemas corporativos críticos — Fintechs, Insurtechs, Banca e Compliance — onde concorrência de dados (*Race Conditions*), quebra de regras operacionais e falta de rastreabilidade representam risco financeiro direto.

---

## Índice

- [Diferenciais de Engenharia & Arquitetura](#️-diferenciais-de-engenharia--arquitetura)
- [Estrutura do Pacote](#-estrutura-do-pacote)
- [Instalação](#-instalação)
- [Configuração Básica](#️-configuração-básica-configlaraflowphp)
- [Como Usar](#-como-usar)
- [Automação Ativa de SLA](#-automação-ativa-de-sla-workflowcheck-slas)
- [Cinto de Utilidades CLI](#-cinto-de-utilidades-cli-devsecops)
- [Gerador de Scaffolding](#-gerador-de-scaffolding-makeworkflow)
- [Licença](#-licença)

---

## 🏗️ Diferenciais de Engenharia & Arquitetura

O LaraFlow separa o ciclo de vida de uma mudança de estado em **três fases isoladas e sequenciais**, otimizando conexões de banco e garantindo isolamento de escopo:

**Fase 1 — Validação Estática (Fora da Transação)**
Checa permissões (`roles`/`permissions`), pré-requisitos relacionais e regras de negócio complexas (`Guards`). Se falhar, o banco de dados permanece intacto, poupando conexões ativas.

**Fase 2 — Bloco Atômico Transacional (Dentro do Lock)**
Aplica **Pessimistic Locking (`lockForUpdate`)** diretamente na linha do registro e executa um *Double-Check* de estado. Se outra requisição alterou o registro em paralelo, a operação sofre rollback e aborta, eliminando cliques duplos e execuções duplicadas.

**Fase 3 — Efeitos Colaterais Pós-Commit (Event-Driven)**
Anuncia as mudanças ao sistema (`WorkflowTransitioned`) apenas após o sucesso absoluto do commit no banco, isolando e protegendo a aplicação contra falhas em APIs ou filas de terceiros.

---

## 📂 Estrutura do Pacote

```text
src/
├── Console/               # Cinto de utilidades CLI de Sustentação e DevOps
├── Contracts/             # Interfaces de Contrato (HasSla, HasEarlyWarning)
├── Events/                # Eventos nativos desacoplados (EDA)
├── Models/                # Modelo imutável de histórico de auditoria
├── Traits/                # Trait de injeção de comportamento nos Eloquent Models
├── Transitions/           # O coração atômico do motor (GenericTransition)
└── WorkflowServiceProvider.php
```

---

## ⚡ Instalação

Instale o pacote via Composer (se o repositório for privado, adicione a referência no seu `composer.json`):

```bash
composer require carlos-bumba-dev/laraflow
```

Publique o arquivo de configuração corporativo:

```bash
php artisan vendor:publish --tag=laraflow-config
```

Execute as migrations para subir as tabelas imutáveis de auditoria e controle de extensões:

```bash
php artisan migrate
```

---

## ⚙️ Configuração Básica (`config/laraflow.php`)

Centralize os modelos Eloquent que o motor deve monitorar ativamente em background:

```php
return [
    'monitored_models' => [
        App\Models\Reclamacao::class,
        App\Models\PropostaCredito::class,
    ],
    'concurrency' => [
        'timeout_seconds' => 10,
    ],
    'compliance' => [
        'system_user_id' => 1, // Fallback para transições via CLI/Cron
    ],
];
```

---

## 🚀 Como Usar

### 1. Prepare o seu Model Eloquent

Adicione a trait `HasStateHistory` e faça o mapeamento do status usando o Spatie States apontando para a nossa `GenericTransition`:

```php
use LaraFlow\Traits\HasStateHistory;
use Spatie\ModelStates\HasStates;

class Reclamacao extends Model
{
    use HasStates, HasStateHistory;

    protected function registerStates(): void
    {
        $this->addState('status', ReclamacaoStatus::class)
            ->transitionTo(Rececionada::class, GenericTransition::class);
    }
}
```

### 2. Disparando uma Transição com Contexto (Payload)

Passe permissões, dependências, guards de negócio e metadados contextuais (ex: o parecer do analista) direto na chamada:

```php
$reclamacao->status->transitionTo(Rececionada::class, [
    'roles' => ['analista-compliance'],
    'payload' => [
        'complaint_id' => $reclamacao->id,
        'user_id'      => auth()->id(),
        'parecer'      => 'Análise concluída com base nos termos regulatórios vigentes.',
    ]
]);
```

---

## 🤖 Automação Ativa de SLA (`workflow:check-slas`)

Se os seus estados implementarem as interfaces `HasSla` ou `HasEarlyWarning`, o LaraFlow transforma-se em um **agente ativo de infraestrutura (self-healing)**. Configure o comando no `app/Console/Kernel.php` para rodar a cada minuto:

```php
$schedule->command('workflow:check-slas')->everyMinute();
```

**🟡 Zona Amarela (Early Warning):** Se o processo se aproximar do estouro, ele move o registro preventivamente para uma fila de prioridade.

**🔴 Zona Vermelha (Transição Compulsória):** Se o prazo morrer, ele remove o registro da mesa do analista e joga para um status de fallback de contingência, disparando o evento `SlaBreached`.

---

## 🧰 Cinto de Utilidades CLI (DevSecOps)

O LaraFlow fornece comandos avançados para a equipe de sustentação intervir no ambiente de produção com **total rastreabilidade**.

**Investigação Forense** — Veja a trilha imutável de um registro diretamente no terminal:

```bash
php artisan laraflow:audit "App\Models\Reclamacao" 1042
```

**Intervenção de Emergência (Chave Mestra)** — Destrave um processo ignorando guards (ex: API de parceiro fora do ar), exigindo justificativa obrigatória para a auditoria:

```bash
php artisan laraflow:force "App\Models\Reclamacao" 1042 "App\States\Rececionada" --reason="Chamado técnico #9021"
```

**Radar de Gargalos** — Identifique quais departamentos estão retendo processos e estourando os indicadores da empresa:

```bash
php artisan laraflow:bottlenecks "App\Models\Reclamacao"
```

**Living Documentation** — Gere diagramas Mermaid atualizados em tempo real com base no código-fonte atual para documentar esteiras de CI/CD:

```bash
php artisan laraflow:visualize "App\States\ReclamacaoStatus"
```

---

## 🛠️ Gerador de Scaffolding (`make:workflow`)

O LaraFlow inclui um comando Artisan para gerar em segundos toda a estrutura de pastas e arquivos necessária para um novo fluxo — estados, guards e actions — a partir de stubs pré-configurados.

```bash
php artisan make:workflow Complaint
```

O comando cria automaticamente a seguinte estrutura dentro do seu projeto:

```text
app/States/Complaint/
├── ComplaintStatus.php          # Classe principal da Máquina de Estados
├── Guards/
│   └── ValidarComplaint.php     # Guard de validação de regras de negócio
└── Actions/
    └── ExecutarAcaoComplaint.php  # Action de efeito colateral pós-transição
```

Os arquivos gerados são populados com os stubs localizados em `src/stubs/`, que servem como ponto de partida funcional e já seguem as convenções do LaraFlow.

---

## 📄 Licença

O LaraFlow é um software open-source licenciado sob a [MIT License](LICENSE.md).

Criado com foco em resiliência por **Carlos Bumba**.
