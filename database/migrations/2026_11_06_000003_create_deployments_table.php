<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved front-end builds (archives of public/build), kept per project.
        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);
            $table->string('label')->nullable();
            $table->string('original_name')->nullable();
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->boolean('has_manifest')->default(false);
            $table->string('commit_hash', 64)->nullable();
            $table->string('branch')->nullable();
            $table->timestamps();
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('deploy');
            $table->string('status', 20)->default('pending');
            $table->string('triggered_by', 20)->default('manual');
            $table->string('branch')->nullable();
            $table->string('commit_hash', 64)->nullable();
            $table->string('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('release')->nullable();
            $table->string('build_source', 20)->nullable();
            $table->string('note')->nullable();
            $table->text('error')->nullable();
            $table->boolean('cancel_requested')->default(false);
            // The release folder of this deployment is still on the server (rollback possible).
            $table->boolean('release_exists')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('status', 20)->default('pending');
            // Custom command steps keep a copy of the script that ran.
            $table->text('script')->nullable();
            $table->longText('output')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_steps');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('builds');
    }
};
