<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

/**
 * Логотип компании — для отчётов (PDF, Word). Храним одним PNG (до 600 px, с прозрачностью):
 * загруженную картинку перерисовываем — так в файле не останется ничего, кроме изображения.
 */
class CompanyLogo
{
    public static function path(Workspace $ws): string
    {
        return storage_path('app/logos/'.$ws->id.'.png');
    }

    public static function exists(Workspace $ws): bool
    {
        return is_file(self::path($ws));
    }

    /** Для страницы настроек и PDF: data:image/png;base64,… */
    public static function dataUri(Workspace $ws): ?string
    {
        return self::exists($ws) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(self::path($ws))) : null;
    }

    public static function save(Workspace $ws, UploadedFile $file): void
    {
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw new \DomainException(__('Не удалось прочитать картинку. Загрузите PNG или JPG.'));
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min(1, 600 / max($w, $h));
        $nw = max(1, (int) round($w * $k));
        $nh = max(1, (int) round($h * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        @mkdir(dirname(self::path($ws)), 0775, true);
        imagepng($dst, self::path($ws), 9);
        imagedestroy($src);
        imagedestroy($dst);
    }

    public static function delete(Workspace $ws): void
    {
        @unlink(self::path($ws));
    }
}
