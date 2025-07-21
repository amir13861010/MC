<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('admins');
    }

    public function down()
    {
        // اگر نیاز به بازگردانی بود، ساختار جدول را بازنویسی کنید
    }
}; 