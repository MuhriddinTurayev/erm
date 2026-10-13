<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    protected $fillable = ['workspace_id', 'hook_id', 'verify_token', 'api_key', 'app_secret', 'page_token', 'public_url'];

    protected $hidden = ['app_secret', 'page_token'];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'page_token' => 'encrypted',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
