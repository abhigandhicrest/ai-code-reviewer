<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('focus_json')->nullable();
            $table->text('extra_instructions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->foreignId('prompt_profile_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('source_type', 16)->default('paste')->after('mode');
            $table->unsignedInteger('files_count')->default(1)->after('chunks_count');
        });

        Schema::create('review_run_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('language', 32)->default('other');
            $table->longText('code');
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();
            $table->unsignedInteger('chunks_count')->default(1);
            $table->timestamps();

            $table->index(['review_run_id', 'path']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('review_run_files');

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_profile_id');
            $table->dropColumn(['source_type', 'files_count']);
        });

        Schema::dropIfExists('prompt_profiles');
    }
};
