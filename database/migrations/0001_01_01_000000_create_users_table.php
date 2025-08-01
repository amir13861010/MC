<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique();
            $table->string('name');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('country');
            $table->string('mobile');
            $table->bigInteger('voucher_id')->nullable(); // فقط فیلد، بدون foreign key
            $table->string('friend_id')->nullable();
            $table->decimal('deposit_balance', 10, 2)->default(0);
            $table->decimal('gain_profit', 10, 2)->default(0);
            $table->decimal('capital_profit', 10, 2)->default(0);
            $table->string('role')->default('user');
            $table->boolean('suspend')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
