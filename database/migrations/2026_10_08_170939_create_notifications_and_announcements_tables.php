<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('type', 40)->default('info');
                $table->string('title');
                $table->text('message')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamps();
            });
        } else {
            Schema::table('notifications', function (Blueprint $table) {
                if (!Schema::hasColumn('notifications', 'user_id'))
                    $table->unsignedBigInteger('user_id')->nullable()->index();

                if (!Schema::hasColumn('notifications', 'type'))
                    $table->string('type', 40)->default('info');

                if (!Schema::hasColumn('notifications', 'title'))
                    $table->string('title')->nullable();

                if (!Schema::hasColumn('notifications', 'message'))
                    $table->text('message')->nullable();

                if (!Schema::hasColumn('notifications', 'is_read'))
                    $table->boolean('is_read')->default(false);

                if (!Schema::hasColumn('notifications', 'created_at'))
                    $table->timestamp('created_at')->nullable();

                if (!Schema::hasColumn('notifications', 'updated_at'))
                    $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('body');
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};