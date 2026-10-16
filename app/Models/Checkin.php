<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    protected $fillable = ['workspace_id', 'token', 'project_id', 'staff_id', 'type', 'lat', 'lng', 'acc', 'dist', 'on_site', 'closed_by', 'note'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'acc' => 'integer', 'dist' => 'integer', 'on_site' => 'boolean'];
    }

    /** Формат для программы (без личного ключа сотрудника) */
    public function toClient(): array
    {
        return [
            'seq' => $this->id,
            't' => $this->created_at?->toIso8601String(),
            'sid' => $this->staff_id,
            'pid' => $this->project_id,
            'type' => $this->type,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'acc' => $this->acc,
            'dist' => $this->dist,
            'on' => $this->on_site,
        ] + array_filter(['by' => $this->closed_by, 'note' => $this->note]);   // смену закрыл руководитель
    }
}
