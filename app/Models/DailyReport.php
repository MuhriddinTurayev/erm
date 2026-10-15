<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReport extends Model
{
    protected $fillable = ['workspace_id', 'date', 'stats', 'ai_summary', 'sent_telegram', 'sent_email', 'error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'stats' => 'array',
            'sent_telegram' => 'boolean',
            'sent_email' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
