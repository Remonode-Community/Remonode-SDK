<?php

namespace Remonode\SDK\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WebhookDelivery extends Model
{
    protected $table = 'remonode_webhook_deliveries';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'url',
        'payload',
        'headers',
        'status_code',
        'response_body',
        'duration_ms',
        'success',
        'attempt',
        'max_attempts',
        'signature_algo',
        'error_message',
        'next_retry_at',
        'delivered_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'success' => 'boolean',
            'created_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Scope: pending retries.
     */
    public function scopePendingRetry($query)
    {
        return $query->where('success', false)
            ->where('attempt', '<', DB::raw('max_attempts'))
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }
}
