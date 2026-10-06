<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 255)->unique();
            $table->string('uid', 64);
            $table->unsignedBigInteger('user_id')->index();
            $table->date('date');
            $table->string('action', 20);
            $table->boolean('used')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};