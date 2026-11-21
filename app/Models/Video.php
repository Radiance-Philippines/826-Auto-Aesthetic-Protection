<?php

namespace App\Models;

use App\Support\YouTube;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A video on the Our work page: a YouTube link (the admin pastes it, the
 * ID is read from it), shown with YouTube's own thumbnail.
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    protected $fillable = ['title', 'youtube_url', 'caption', 'is_published', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Video $video) {
            $video->youtube_id = YouTube::videoId($video->youtube_url) ?? $video->youtube_id;
        });
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Shape used by the React site (window.APP826.videos).
     *
     * @return array{id: int, title: string, caption: string|null, youtubeId: string, thumbnail: string, url: string}
     */
    public function toSite(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'caption' => $this->caption,
            'youtubeId' => $this->youtube_id,
            'thumbnail' => YouTube::thumbnailUrl($this->youtube_id),
            'url' => YouTube::watchUrl($this->youtube_id),
        ];
    }
}
