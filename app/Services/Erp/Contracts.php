<?php

namespace App\Services\Erp;

use App\Models\Contract;
use App\Models\Workspace;

/**
 * Расчёты по договорам — в валюте договора.
 * Оплата из кассы в той же валюте считается как есть (договор на $10 000 оплачен $10 000 — долга нет при любом курсе);
 * оплата из кассы в другой валюте пересчитывается по курсу.
 */
class Contracts
{
    /** Оплачено по договору — в валюте договора */
    public static function paid(Workspace $ws, Contract $c): float
    {
        $type = $c->direction === 'out' ? 'out' : 'in';
        $sum = 0.0;
        foreach ($c->ops()->with('desk')->where('type', $type)->whereNull('transfer_key')->get() as $op) {
            $sum += $op->desk && $op->desk->currency === $c->currency
                ? (float) $op->amount
                : Money::fromBase($ws, (float) $op->amount_base, $c->currency);
        }

        return round($sum, 2);
    }

    /** Остаток по договору в валюте договора (плюс — ещё не оплачено) */
    public static function left(Workspace $ws, Contract $c): float
    {
        $left = round((float) $c->amount - self::paid($ws, $c), 2);

        return abs($left) < 0.01 ? 0.0 : $left;
    }

    /** Остаток по договору в валюте компании (для сводок и списков) */
    public static function debtBase(Workspace $ws, Contract $c): float
    {
        return Money::toBase($ws, self::left($ws, $c), $c->currency);
    }
}
