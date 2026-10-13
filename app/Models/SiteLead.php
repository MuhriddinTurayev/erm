<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Заявка с сайта платформы на бесплатный разбор (видит администратор платформы) */
class SiteLead extends Model
{
    /** Варианты из формы: ключ — для базы, значение — текст (ключ перевода сайта) */
    public const KINDS = [
        'residential' => 'Многоэтажные жилые дома',
        'commercial' => 'Коммерческая недвижимость',
        'government' => 'Государственные заказы',
        'industrial' => 'Промышленные объекты',
        'houses' => 'Частные дома и коттеджи',
        'infra' => 'Дороги и инфраструктура',
    ];

    public const OBJECTS = ['1' => '1 объект', '2-3' => '2–3 объекта', '4-10' => '4–10 объектов', '10+' => 'Больше 10'];

    public const ROLES = [
        'owner' => 'Владелец / директор',
        'deputy' => 'Заместитель директора',
        'finance' => 'Финансовый директор / бухгалтер',
        'engineer' => 'Главный инженер / прораб',
        'other' => 'Другая должность',
    ];

    public const STATUSES = ['new' => 'Новая', 'called' => 'Связались', 'done' => 'Демонстрация проведена', 'spam' => 'Спам'];

    protected $fillable = ['name', 'phone', 'company', 'kind', 'objects', 'role', 'budget', 'locale', 'source', 'ip', 'status', 'note'];
}
