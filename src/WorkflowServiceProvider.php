<?php

namespace LaraFlow\Workflow;

use Illuminate\Support\ServiceProvider;
use LaraFlow\Workflow\Console\MakeWorkflowCommand;

class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Carrega automaticamente a migration do pacote para a aplicação principal
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeWorkflowCommand::class,
            ]);
        }
    }
}