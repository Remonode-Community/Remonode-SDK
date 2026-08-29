<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remonode_api_keys', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('environment')
                ->comment('Array of allowed scopes: ["read","write","admin"]. null = all access');
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('scopes')
                ->comment('Per-key rate limit override. null = use global default');
            $table->unsignedInteger('monthly_quota')->nullable()->after('rate_limit_per_minute')
                ->comment('Per-key monthly call limit. null = unlimited');
        });
    }

    public function down(): void
    {
        Schema::table('remonode_api_keys', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'rate_limit_per_minute', 'monthly_quota']);
        });
    }
};
