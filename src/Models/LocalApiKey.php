<?php

namespace Remonode\SDK\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocalApiKey extends Model
{
    use SoftDeletes;

    protected $table = 'remonode_api_keys';

    protected $fillable = [
        'user_id',
        'app_uuid',
        'key_id',
        'secret_prefix',
        'public_key',
        'secret_hash',
        'secret_last_four',
        'name',
        'status',
        'environment',
        'scopes',
        'rate_limit_per_minute',
        'monthly_quota',
        'remote_id',
        'expires_at',
        'last_used_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'scopes' => 'array',
            'rate_limit_per_minute' => 'integer',
            'monthly_quota' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('remonode.user_model', \App\Models\User::class));
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Masked display: sk_...wxyz
     */
    public function maskedKey(): string
    {
        $prefix = substr($this->key_id, 0, strrpos($this->key_id, '_') + 1);
        return "{$prefix}...{$this->secret_last_four}";
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
