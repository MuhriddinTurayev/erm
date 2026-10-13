<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSite extends Model
{
    protected $fillable = ['workspace_id', 'project_id', 'name', 'lat', 'lng', 'radius'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'radius' => 'integer'];
    }
}
