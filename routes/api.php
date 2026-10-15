<?php

use App\Http\Controllers\Api\V1Controller as V1;
use Illuminate\Support\Facades\Route;

/*
 | Открытый API для других программ: /api/v1/…
 | Ключ — «Компания → API и интеграции», заголовок «Authorization: Bearer oft_…».
 | Право на раздел — после двоеточия: api.key:objects:read
 */
Route::prefix('v1')->group(function () {
    Route::get('/', [V1::class, 'index']);
    Route::get('/openapi.json', [V1::class, 'openapi']);

    Route::get('/me', [V1::class, 'me'])->middleware('api.key');
    Route::get('/users', [V1::class, 'users'])->middleware('api.key:tasks:read');

    Route::middleware('api.key:objects:read')->group(function () {
        Route::get('/objects', [V1::class, 'objects']);
        Route::get('/objects/{object}', [V1::class, 'object']);
        Route::get('/objects/{object}/apartments', [V1::class, 'apartments']);
        Route::get('/objects/{object}/apartments/{apartment}', [V1::class, 'apartment']);
    });
    Route::patch('/objects/{object}/apartments/{apartment}', [V1::class, 'apartmentUpdate'])->middleware('api.key:sales:write');
    Route::get('/sales/payments', [V1::class, 'salesPayments'])->middleware('api.key:sales:read');

    Route::get('/leads', [V1::class, 'leads'])->middleware('api.key:leads:read');
    Route::post('/leads', [V1::class, 'leadStore'])->middleware('api.key:leads:write');

    Route::middleware('api.key:stock:read')->group(function () {
        Route::get('/stock/items', [V1::class, 'stockItems']);
        Route::get('/stock/warehouses', [V1::class, 'stockWarehouses']);
        Route::get('/stock/moves', [V1::class, 'stockMoves']);
    });

    Route::middleware('api.key:cash:read')->group(function () {
        Route::get('/cash/desks', [V1::class, 'cashDesks']);
        Route::get('/cash/operations', [V1::class, 'cashOps']);
    });
    Route::get('/payment-requests', [V1::class, 'paymentRequests'])->middleware('api.key:payments:read');

    Route::get('/counterparties', [V1::class, 'counterparties'])->middleware('api.key:parties:read');
    Route::get('/counterparties/{id}', [V1::class, 'counterparty'])->whereNumber('id')->middleware('api.key:parties:read');
    Route::post('/counterparties', [V1::class, 'counterpartyStore'])->middleware('api.key:parties:write');
    Route::patch('/counterparties/{id}', [V1::class, 'counterpartyUpdate'])->whereNumber('id')->middleware('api.key:parties:write');

    Route::get('/tasks', [V1::class, 'tasks'])->middleware('api.key:tasks:read');
    Route::get('/tasks/{id}', [V1::class, 'task'])->whereNumber('id')->middleware('api.key:tasks:read');
    Route::post('/tasks', [V1::class, 'taskStore'])->middleware('api.key:tasks:write');
    Route::patch('/tasks/{id}', [V1::class, 'taskUpdate'])->whereNumber('id')->middleware('api.key:tasks:write');
});
