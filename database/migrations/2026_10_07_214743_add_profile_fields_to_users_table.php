<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('student_id')->nullable()->unique()->after('email');
            $table->string('contact_number', 20)->nullable()->after('student_id');
            $table->text('address')->nullable()->after('contact_number');

            if (!Schema::hasColumn('users', 'photo')) {
                $table->string('photo')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name',
                'student_id', 'contact_number', 'address',
            ]);
        });
    }
};  