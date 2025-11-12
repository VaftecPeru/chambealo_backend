<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id('media_id');
            $table->string('related_type'); // Ej: 'App\\Models\\Product', 'App\\Models\\Brand'
            $table->unsignedBigInteger('related_id');
            $table->string('file_url');
            $table->string('file_name');
            $table->string('file_type')->nullable()->comment('MIME type');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('collection')->default('default')->comment('Ej: images, documents, etc.');
            $table->integer('order_column')->nullable()->comment('Para ordenar archivos');
            $table->json('custom_properties')->nullable()->comment('Metadatos adicionales');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            // Índice para relaciones polimórficas
            $table->index(['related_type', 'related_id']);
            $table->index(['collection', 'order_column']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('media');
    }
};
