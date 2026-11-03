<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // short notes the OJT coordinator writes next to a requirement (key = the requirement key, "__general" = the note at the top)
        Schema::create('requirement_form_notes', function (Blueprint $t) {
            $t->string('requirement', 60)->primary();
            $t->text('note');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_form_notes');
    }
};