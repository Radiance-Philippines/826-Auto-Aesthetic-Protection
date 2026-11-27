<?php

namespace App\Support;

/**
 * What the admin takes as a picture upload: photos (shrunk in the browser to
 * ImagePipeline::MASTER_MAX_EDGE before they are sent, converted to WebP on
 * save) and QR codes (kept as uploaded, so they scan). Never SVG.
 */
final class UploadRules
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const PHOTO_MAX_KB = 20480;

    public const QR_MAX_KB = 5120;

    /**
     * @return list<string>
     */
    public static function image(int $maxKb): array
    {
        return ['file', 'mimetypes:'.implode(',', self::TYPES), 'max:'.$maxKb];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field, int $maxKb): array
    {
        return [
            "{$field}.required" => 'Choose a picture.',
            "{$field}.file" => 'Choose a picture.',
            "{$field}.mimetypes" => 'Use a JPG, PNG or WebP picture.',
            "{$field}.max" => 'Keep the picture under '.($maxKb / 1024).' MB.',
            // PHP refused it before Laravel saw it (upload_max_filesize)
            "{$field}.uploaded" => 'That file is too big for the server. Try a smaller one.',
        ];
    }
}
