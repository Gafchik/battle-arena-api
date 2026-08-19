<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('battles', function (Blueprint $table) {
            $table->id();
            $table->enum('mode', ['pve', 'pvp']);
            $table->foreignId('player_a_id')->constrained('users');
            $table->foreignId('player_b_id')->nullable()->constrained('users');
            $table->string('model_a')->nullable();
            $table->string('model_b')->nullable();
            $table->unsignedInteger('a_hp')->default(100);
            $table->unsignedInteger('b_hp')->default(100);
            $table->enum('status', ['waiting_for_opponent', 'in_progress', 'completed'])->default('in_progress');
            $table->enum('winner', ['a', 'b', 'draw', 'forfeit_both'])->nullable();

            // PvP only: the move each side has committed for the round currently
            // being decided, cleared once both sides are in and the round resolves.
            $table->enum('a_pending_attack', ['head', 'chest', 'groin', 'legs'])->nullable();
            $table->json('a_pending_defend')->nullable();
            $table->enum('b_pending_attack', ['head', 'chest', 'groin', 'legs'])->nullable();
            $table->json('b_pending_defend')->nullable();
            $table->timestamp('round_deadline_at')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battles');
    }
};
