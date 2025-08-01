<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->decimal('leg_a_balance', 10, 2);
            $table->decimal('leg_b_balance', 10, 2);
            $table->decimal('leg_c_balance', 10, 2);
            $table->decimal('reward_amount', 10, 2)->nullable();
            $table->boolean('is_rewarded')->default(false);
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('rewards');
    }
}; 