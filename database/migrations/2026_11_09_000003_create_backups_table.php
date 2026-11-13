<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Database backups (.sql.gz) kept on the server in <deploy_path>/backups/db/.
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The deployment (deploy, backup, restore or rollback) that made it.
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('file');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('db_connection', 20);
            $table->string('database')->nullable();
            // The release that was live when it was made.
            $table->string('release')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });

        // A restore (or a rollback with the database) knows which backup it puts back.
        Schema::table('deployments', function (Blueprint $table) {
            $table->foreignId('restore_backup_id')->nullable()->after('build_id')->constrained('backups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restore_backup_id');
        });

        Schema::dropIfExists('backups');
    }
};
