<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Надёжность и безопасность:
 *  — email всегда в нижнем регистре (вход не зависит от регистра в любой СУБД);
 *  — ссылка покупателя привязана к сделке: после продажи квартиры другому клиенту старая ссылка не открывается;
 *  — оплата подписки с ключом повтора: двойное нажатие не продлевает доступ дважды.
 */
return new class extends Migration
{
    public function up(): void
    {
        // email → нижний регистр (если два адреса отличаются только регистром — второй не трогаем, чтобы не нарушить уникальность)
        $seen = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $u) {
            $low = mb_strtolower(trim((string) $u->email));
            if (isset($seen[$low])) {
                continue;
            }
            $seen[$low] = true;
            if ($low !== $u->email) {
                DB::table('users')->where('id', $u->id)->update(['email' => $low]);
            }
        }

        if (! Schema::hasColumn('buyer_links', 'deal')) {
            Schema::table('buyer_links', function (Blueprint $t) {
                $t->string('deal', 40)->nullable()->after('apt_id');
            });
        }
        if (! Schema::hasColumn('subscription_payments', 'idem')) {
            Schema::table('subscription_payments', function (Blueprint $t) {
                $t->string('idem', 64)->nullable()->unique()->after('note');
            });
        }

        // номера сделок для проданных и забронированных квартир; выданные ссылки — к текущему покупателю
        \App\Services\Deals::normalizeAll();
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscription_payments', 'idem')) {
            Schema::table('subscription_payments', fn (Blueprint $t) => $t->dropUnique(['idem']));
            Schema::table('subscription_payments', fn (Blueprint $t) => $t->dropColumn('idem'));
        }
        if (Schema::hasColumn('buyer_links', 'deal')) {
            Schema::table('buyer_links', fn (Blueprint $t) => $t->dropColumn('deal'));
        }
    }
};
