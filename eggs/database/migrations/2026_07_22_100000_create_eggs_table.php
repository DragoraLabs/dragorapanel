<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eggs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('author')->nullable();
            $table->string('type', 50)->default('minecraft');
            $table->string('docker_image')->nullable();
            $table->string('startup_command')->nullable();
            $table->json('config_files')->nullable();
            $table->string('default_version')->nullable();
            $table->string('java_version', 10)->default('21');
            $table->json('supported_versions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('egg_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('env_variable', 50);
            $table->string('default_value')->nullable();
            $table->string('rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('user_viewable')->default(true);
            $table->boolean('user_editable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('egg_id')->nullable()->constrained()->nullOnDelete()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['egg_id']);
            $table->dropColumn('egg_id');
        });
        Schema::dropIfExists('egg_variables');
        Schema::dropIfExists('eggs');
    }
};
