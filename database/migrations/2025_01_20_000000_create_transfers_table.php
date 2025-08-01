<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_id', 10)->unique();
            $table->string('from_user_id');
            $table->string('to_user_id')->nullable(); // null برای انتقال داخلی (capital_profit به gain_profit)
            $table->decimal('amount', 10, 2);
            $table->enum('from_account', ['capital_profit', 'gain_profit']);
            $table->enum('to_account', ['capital_profit', 'gain_profit']);
            $table->enum('transfer_type', ['internal', 'external']); // internal: انتقال داخلی، external: انتقال به کاربر دیگر
            $table->text('description')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('from_user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('to_user_id')->references('user_id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index(['from_user_id', 'created_at']);
            $table->index(['to_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};