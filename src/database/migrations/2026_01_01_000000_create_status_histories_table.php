<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function run(): void
    {
        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            
            // Relacionamento polimórfico (Funciona para qualquer Model: Proposta, Complaint, etc.)
            $table->morphs('model'); 
            
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->json('payload')->nullable();
            
            // Identifica quem realizou a transição (Suporta IDs numéricos ou UUIDs se alterado para string)
            $table->unsignedBigInteger('user_id')->nullable()->index(); 
            
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_histories');
    }
};