<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remonode_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app_uuid', 36)->nullable()->index();
            $table->string('key_id', 100)->unique()->index();
            $table->string('secret_prefix', 16)->index();
            $table->text('public_key')->nullable();
            $table->string('secret_hash')->nullable();
            $table->string('secret_last_four', 4)->nullable();
            $table->string('name')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('environment', 20)->default('production');
            $table->string('remote_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remonode_api_keys');
    }
};
