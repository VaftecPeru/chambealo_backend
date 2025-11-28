<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_media_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id('media_id');
            $table->string('related_type'); // Ej: 'Product', 'User', 'Brand'
            $table->unsignedBigInteger('related_id');
            $table->string('file_url');
            $table->string('file_type'); // Ej: 'image', 'video', 'document'
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('media');
    }
};