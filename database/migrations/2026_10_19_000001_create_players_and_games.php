<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('first_name', 60);
            $table->string('last_name', 80);
            $table->string('nickname', 40)->nullable();
            $table->boolean('use_nickname')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('games', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->date('played_on')->index();
            $table->unsignedInteger('daily_number');
            $table->unsignedTinyInteger('player_count');
            $table->enum('status', ['completed', 'interrupted']);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['played_on', 'daily_number']);
        });
        Schema::create('game_participants', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignUuid('game_id')->constrained('games')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->unsignedTinyInteger('starting_order');
            $table->unsignedTinyInteger('place')->nullable();
            $table->unique(['game_id', 'player_id']);
            $table->unique(['game_id', 'place']);
            $table->unique(['game_id', 'starting_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_participants');
        Schema::dropIfExists('games');
        Schema::dropIfExists('players');
    }
};
