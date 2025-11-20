<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id('token_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('token_hash')->unique();
            $table->string('ip_address');
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index('token_hash');
        });
    }

    public function down()
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
