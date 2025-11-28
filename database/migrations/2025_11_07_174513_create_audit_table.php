<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_audits_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id('audit_id');
            $table->string('entity_type'); // Ej: 'User', 'Product'
            $table->unsignedBigInteger('entity_id');
            $table->string('action'); // Ej: 'created', 'updated', 'deleted'
            $table->foreignId('performed_by')->constrained('users', 'user_id');
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('timestamp')->useCurrent();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('audits');
    }
};