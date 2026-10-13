<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadInbox extends Model
{
    protected $table = 'lead_inbox';

    protected $fillable = ['workspace_id', 'ch', 'kind', 'key', 'name', 'phone', 'user', 'src', 'rooms', 'budget', 'text'];

    /** Формат, который ждёт программа (поле seq — номер для «новые после N») */
    public function toClient(): array
    {
        return [
            'seq' => $this->id,
            't' => $this->created_at?->toIso8601String(),
            'ch' => $this->ch,
            'kind' => $this->kind,
            'key' => $this->key,
            'name' => $this->name,
            'phone' => $this->phone,
            'user' => $this->user,
            'src' => $this->src,
            'rooms' => $this->rooms,
            'budget' => $this->budget,
            'text' => $this->text,
        ];
    }
}
