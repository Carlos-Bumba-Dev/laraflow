<?php

namespace LaraFlow\Workflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeWorkflowCommand extends Command
{
    protected $signature = 'make:workflow {name : O nome do Model/Fluxo (ex: Complaint)}';
    protected $description = 'Gera uma estrutura completa e pragmática de Máquina de Estados';

    public function handle(): int
    {
        $name = ucfirst($this->argument('name'));
        $targetFolder = app_path("States/{$name}");
        $namespace = "App\\States\\{$name}";

        if (File::exists($targetFolder)) {
            $this->error("O fluxo para {$name} já existe!");
            return Command::FAILURE;
        }

        File::makeDirectory($targetFolder, 0755, true);
        File::makeDirectory("{$targetFolder}/Guards", 0755, true);
        File::makeDirectory("{$targetFolder}/Actions", 0755, true);

        $this->generateFile('state.workflow.stub', "{$targetFolder}/{$name}Status.php", $namespace, $name);
        $this->generateFile('guard.workflow.stub', "{$targetFolder}/Guards/Validar{$name}.php", $namespace, $name);
        $this->generateFile('action.workflow.stub', "{$targetFolder}/Actions/ExecutarAcao{$name}.php", $namespace, $name);

        $this->info("🚀 Estrutura de Workflow para [{$name}] criada com sucesso no projeto local!");
        return Command::SUCCESS;
    }

    protected function generateFile(string $stubName, string $destination, string $namespace, string $class): void
    {
        // Lê os stubs relativos ao diretório do pacote de forma segura
        $stubPath = __DIR__ . '/../../stubs/' . $stubName;
        $stubContent = File::get($stubPath);
        
        $compiled = str_replace(
            ['{{namespace}}', '{{class}}'],
            [$namespace, $class],
            $stubContent
        );

        File::put($destination, $compiled);
    }
}