<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_standards', function (Blueprint $table) {
            $table->id();
            $table->string('language', 32)->unique();
            $table->string('name');
            $table->longText('rules_text');
            $table->string('linter', 32)->default('none');
            $table->string('linter_args')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_standards');
    }
};
