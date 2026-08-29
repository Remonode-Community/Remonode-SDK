<?php

namespace Remonode\SDK\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    protected $table = 'remonode_api_usage_logs';

    public $timestamps = false;

    protected $fillable = [
        'api_key_id',
        'user_id',
        'method',
        'path',
        'route_name',
        'status_code',
        'response_time_ms',
        'ip_address',
        'user_agent',
        'environment',
        'scope_used',
        'rate_limited',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'rate_limited' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(LocalApiKey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('remonode.user_model', \App\Models\User::class));
    }

    /**
     * Scope: logs for a specific period.
     */
    public function scopeForPeriod($query, \DateTimeInterface $from, \DateTimeInterface $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope: this month's usage for a key.
     */
    public function scopeThisMonth($query, int $keyId)
    {
        return $query->where('api_key_id', $keyId)
            ->where('created_at', '>=', now()->startOfMonth());
    }

    /**
     * Get usage count for a key in the current month.
     */
    public static function monthlyCount(int $keyId): int
    {
        return static::where('api_key_id', $keyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
