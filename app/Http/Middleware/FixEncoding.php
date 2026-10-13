<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Текст не в UTF-8 (старые программы, консольные клиенты Windows) переводим из Windows-1251, чтобы не ломать сессию и базу */
class FixEncoding
{
    public function handle(Request $request, Closure $next)
    {
        $fix = function ($v) use (&$fix) {
            if (is_array($v)) {
                return array_map($fix, $v);
            }

            return is_string($v) && ! mb_check_encoding($v, 'UTF-8') ? mb_convert_encoding($v, 'UTF-8', 'Windows-1251') : $v;
        };
        $request->query->replace($fix($request->query->all()));
        $request->request->replace($fix($request->request->all()));

        return $next($request);
    }
}
