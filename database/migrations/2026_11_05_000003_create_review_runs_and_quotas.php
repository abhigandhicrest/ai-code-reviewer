<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language', 32);
            $table->longText('code');
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->longText('review')->nullable();
            $table->json('findings')->nullable();
            $table->longText('improved_code')->nullable();
            $table->boolean('parsed')->default(false);
            $table->boolean('success')->default(false);
            $table->unsignedInteger('chunks_count')->default(1);
            $table->unsignedInteger('code_chars')->default(0);
            $table->unsignedInteger('estimated_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('findings_count')->default(0);
            $table->string('mode', 16)->default('stream');
            $table->string('model')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['success', 'created_at']);
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreignId('review_run_id')
                ->nullable()
                ->after('user_id')
                ->constrained('review_runs')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('daily_review_quota')->nullable()->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_run_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('daily_review_quota');
        });

        Schema::dropIfExists('review_runs');
    }
};
