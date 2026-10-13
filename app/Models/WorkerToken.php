<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerToken extends Model
{
    protected $fillable = ['workspace_id', 'token', 'project_id', 'staff_id', 'name', 'role'];
}
