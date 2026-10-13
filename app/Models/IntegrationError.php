<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationError extends Model
{
    protected $fillable = ['workspace_id', 'msg'];

    public static function log(int $workspaceId, string $msg): void
    {
        static::create(['workspace_id' => $workspaceId, 'msg' => mb_substr($msg, 0, 300)]);
        logger()->warning('[oftob] '.$msg);
        // храним только последние 50
        $old = static::where('workspace_id', $workspaceId)->orderByDesc('id')->skip(50)->take(1000)->pluck('id');
        if ($old->isNotEmpty()) {
            static::whereIn('id', $old)->delete();
        }
    }
}
