<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->decimal('leg_a_balance', 16, 2)->default(0);
            $table->decimal('leg_b_balance', 16, 2)->default(0);
            $table->decimal('leg_c_balance', 16, 2)->default(0);
            $table->decimal('reward_amount', 16, 2)->default(0);
            $table->boolean('is_rewarded')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_rewards');
    }
}; 