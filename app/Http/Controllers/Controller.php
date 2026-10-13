<?php

namespace App\Http\Controllers;

use App\Models\Workspace;

abstract class Controller
{
    /** Компания текущего пользователя */
    protected function ws(): Workspace
    {
        return auth()->user()->workspace;
    }
}
