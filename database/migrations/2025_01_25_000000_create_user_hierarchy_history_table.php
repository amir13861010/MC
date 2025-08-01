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
        Schema::create('user_hierarchy_history', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('parent_user_id');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable(); // For tracking when user left/changed parent
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('parent_user_id')->references('user_id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index(['user_id', 'joined_at']);
            $table->index(['parent_user_id', 'joined_at']);
            $table->index(['user_id', 'left_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_hierarchy_history');
    }
}; 