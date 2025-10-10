<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        // Insertar roles por defecto
        DB::table('roles')->insert([
            ['nombre' => 'superadministrador', 'descripcion' => 'Acceso total al sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'administrador', 'descripcion' => 'Gestión de productos y pedidos', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'cliente', 'descripcion' => 'Usuario registrado que puede comprar', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'invitado', 'descripcion' => 'Usuario sin registro', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};