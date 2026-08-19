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
        Schema::create('battle_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained('battles')->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->enum('a_attack', ['head', 'chest', 'groin', 'legs'])->nullable();
            $table->json('a_defend')->nullable();
            $table->enum('b_attack', ['head', 'chest', 'groin', 'legs'])->nullable();
            $table->json('b_defend')->nullable();
            $table->unsignedInteger('a_damage');
            $table->unsignedInteger('b_damage');
            $table->boolean('a_blocked');
            $table->boolean('b_blocked');
            $table->unsignedInteger('a_hp_after');
            $table->unsignedInteger('b_hp_after');
            $table->text('text');
            $table->timestamps();

            $table->unique(['battle_id', 'round']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battle_rounds');
    }
};
