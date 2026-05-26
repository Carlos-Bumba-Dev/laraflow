<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function run(): void
    {
        Schema::create('workflow_extensions', function (Blueprint $table) {
            $table->id();
            $table->morphs('model'); // Associa a qualquer Model (Complaint, Processo, etc.)
            
            $table->string('state'); // Guarda a classe do estado onde a extensão foi aplicada
            $table->integer('extended_minutes'); // Quantos minutos foram adicionados ao prazo
            $table->text('reason'); // A justificativa obrigatória para fins de compliance
            $table->unsignedBigInteger('user_id')->index(); // Quem autorizou a extensão
            
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_extensions');
    }
};