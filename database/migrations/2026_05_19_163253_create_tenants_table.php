<?php
// database/migrations/2026_05_19_170000_create_tenants_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id('tenant_id'); // ← usar tenant_id como primary key
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('database')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Configuraciones adicionales
            $table->timestamps();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('tenants');
    }
}