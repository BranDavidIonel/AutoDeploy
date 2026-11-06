<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel queue workers kept alive by Supervisor on the server.
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('connection')->default('database');
            $table->string('queue')->default('default');
            $table->unsignedTinyInteger('processes')->default(1);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedInteger('sleep')->default(3);
            $table->unsignedInteger('tries')->default(3);
            $table->unsignedInteger('max_time')->default(3600);
            $table->unsignedInteger('memory')->default(128);
            $table->string('user')->nullable();
            $table->string('status', 20)->default('not_installed');
            $table->text('status_output')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
