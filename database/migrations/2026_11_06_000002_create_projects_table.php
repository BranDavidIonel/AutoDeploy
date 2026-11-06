<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('url')->nullable();
            $table->string('repository');
            $table->string('branch')->default('main');
            $table->string('deploy_path');
            $table->string('php_binary')->default('php');
            $table->string('composer_binary')->default('composer');
            $table->boolean('run_composer')->default(true);
            $table->string('composer_flags')->default('--no-dev --optimize-autoloader --no-interaction --prefer-dist');
            $table->unsignedTinyInteger('keep_releases')->default(3);

            // Front-end build
            $table->string('build_strategy', 20)->default('local');
            $table->string('server_build_command')->default('npm ci && npm run build');
            $table->string('local_path')->nullable();
            $table->string('local_build_command')->default('npm run build');
            $table->string('build_output')->default('public/build');
            $table->unsignedTinyInteger('keep_builds')->default(3);

            // Files and folders kept between releases (symlinked from shared/)
            $table->json('shared_dirs')->nullable();
            $table->json('shared_files')->nullable();
            $table->string('web_user')->nullable()->default('www-data');

            $table->string('health_check_url')->nullable();
            $table->boolean('auto_deploy')->default(false);
            $table->string('webhook_token', 64)->unique();
            $table->boolean('scheduler_enabled')->default(false);

            $table->string('active_release')->nullable();
            $table->unsignedBigInteger('active_deployment_id')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('project_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('stage', 30);
            $table->text('script');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'stage', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_hooks');
        Schema::dropIfExists('projects');
    }
};
