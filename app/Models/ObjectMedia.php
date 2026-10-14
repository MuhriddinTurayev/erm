<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 3D-модель, панорама 360°, планировка, рендер или видео объекта */
class ObjectMedia extends Model
{
    protected $table = 'object_media';

    public const KINDS = [
        'exterior' => ['Фасад и здание 3D', 'building-2', ['glb', 'gltf']],
        'interior' => ['Интерьер квартиры 3D', 'sofa', ['glb', 'gltf']],
        'pano' => ['Панорама 360°', 'compass', ['jpg', 'jpeg', 'png', 'webp']],
        'plan' => ['Планировка', 'ruler', ['jpg', 'jpeg', 'png', 'webp', 'pdf']],
        'photo' => ['Рендер / фото', 'image', ['jpg', 'jpeg', 'png', 'webp']],
        'video' => ['Видео-обзор', 'circle-play', ['mp4', 'webm']],
    ];

    protected $fillable = ['workspace_id', 'project_id', 'kind', 'rooms', 'layout', 'title', 'path', 'ext', 'size', 'poster', 'is_public', 'is_main', 'sort', 'meta', 'user_id'];

    /**
     * Файлы для квартиры: сначала привязанные к её планировке, затем (для старых файлов без планировки) — по числу комнат,
     * затем общие. $list — коллекция файлов объекта.
     */
    public static function forUnit($list, ?string $layout, int $rooms)
    {
        $own = $layout ? $list->where('layout', $layout) : collect();

        return $own->count() ? $own->values()
            : $list->filter(fn ($m) => $m->layout === null && ($m->rooms === null || (int) $m->rooms === $rooms))->values();
    }

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_main' => 'boolean', 'meta' => 'array', 'rooms' => 'integer'];
    }

    public function scopeOf($q, int $workspaceId)
    {
        return $q->where('object_media.workspace_id', $workspaceId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function is3d(): bool
    {
        return in_array($this->ext, ['glb', 'gltf'], true);
    }

    public function isImage(): bool
    {
        return in_array($this->ext, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    public static function mimeOf(string $ext): string
    {
        return ['glb' => 'model/gltf-binary', 'gltf' => 'model/gltf+json', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm'][$ext] ?? 'application/octet-stream';
    }

    /** Адрес файла для сотрудника компании */
    public function url(): string
    {
        return url('/company/media/'.$this->id.'/file');
    }
}
