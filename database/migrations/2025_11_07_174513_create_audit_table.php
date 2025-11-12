<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('audit', function (Blueprint $table) {
            $table->id('audit_id');
            $table->string('entity_type'); // Ej: 'App\\Models\\Product', 'App\\Models\\User'
            $table->unsignedBigInteger('entity_id');
            $table->string('action'); // Ej: 'created', 'updated', 'deleted'
            $table->json('old_values')->nullable()->comment('Valores anteriores');
            $table->json('new_values')->nullable()->comment('Valores nuevos');
            $table->text('description')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users', 'user_id');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('timestamp')->useCurrent();
            // NOTA: No usamos timestamps() ni softDeletes en audit para mantener registro limpio

            // Índices para mejor performance
            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'timestamp']);
            $table->index(['performed_by', 'timestamp']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('audit');
    }
};
