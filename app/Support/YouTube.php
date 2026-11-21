<?php

namespace App\Support;

/**
 * YouTube links only (no video uploads, so nothing heavy sits in storage):
 * the video ID from any share link, the privacy-enhanced embed, and
 * YouTube's own thumbnail, which is linked, never downloaded.
 */
class YouTube
{
    /**
     * youtube.com/watch?v=ID, youtu.be/ID, youtube.com/shorts/ID, /embed/ID,
     * /live/ID and music.youtube.com → ID.
     */
    public static function videoId(?string $url): ?string
    {
        if (! $url || ! preg_match('~^https://(?:www\.|m\.|music\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', trim($url), $match)) {
            return null;
        }

        return $match[1];
    }

    public static function watchUrl(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    public static function thumbnailUrl(string $id): string
    {
        return "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
    }
}
