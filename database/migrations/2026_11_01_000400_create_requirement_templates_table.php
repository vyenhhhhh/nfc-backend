<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // the blank forms the OJT coordinator gives to interns (to download and print), one row per file
        Schema::create('requirement_templates', function (Blueprint $t) {
            $t->id();
            $t->string('requirement', 60)->nullable();   // the requirement key it belongs to (null = other form)
            $t->string('title', 150);
            $t->string('file_path');
            $t->string('original_name')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->index('requirement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_templates');
    }
};