<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language', 32);
            $table->unsignedInteger('code_chars')->default(0);
            $table->unsignedInteger('estimated_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('findings_count')->default(0);
            $table->boolean('success')->default(false);
            $table->boolean('parsed')->default(false);
            $table->string('mode', 16)->default('stream'); // stream|sync
            $table->string('model')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
