<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id('message_id');
            $table->foreignId('sender_id')->constrained('users', 'user_id');
            $table->foreignId('received_id')->constrained('users', 'user_id');
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            // Índices para mejor performance
            $table->index(['sender_id', 'sent_at']);
            $table->index(['received_id', 'is_read', 'sent_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('messages');
    }
};
