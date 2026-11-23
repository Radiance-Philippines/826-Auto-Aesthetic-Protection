<?php

namespace App\Support\Live;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Radiance Flow as the live channel: events go to Flow's topics over its
 * HTTPS Connect JSON API, after the database transaction commits. This app
 * is the record, Flow only carries "something changed", so a failed publish
 * is logged and forgotten: nothing here ever breaks a request. Every event
 * carries its own dedupe id, so a retry (or a repeat) is ignored by Flow,
 * and the screens treat every event as "look again", which is safe twice.
 */
final class FlowLive
{
    private const PAUSE_KEY = 'flow-live-paused';

    /**
     * Publishing needs the app token; without one live updates are off.
     */
    public static function enabled(): bool
    {
        return self::secret('app_token') !== null;
    }

    /**
     * Whether browsers can be given tickets.
     */
    public static function ticketsEnabled(): bool
    {
        return self::secret('ticket_secret') !== null;
    }

    /**
     * Sends one event to each topic, once the surrounding transaction commits.
     *
     * @param  list<string>  $topics
     * @param  array<string, mixed>  $payload  ids and what changed, nothing more
     */
    public static function publish(array $topics, string $type, array $payload): void
    {
        $topics = array_values(array_unique($topics));

        if ($topics === [] || ! self::enabled()) {
            return;
        }

        DB::afterCommit(fn () => self::send($topics, $type, $payload));
    }

    /**
     * @param  list<string>  $topics
     * @param  array<string, mixed>  $payload
     */
    private static function send(array $topics, string $type, array $payload): void
    {
        if (Cache::has(self::PAUSE_KEY)) {
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = (string) Str::ulid();

        $events = array_map(fn (string $topic) => [
            'topic' => $topic,
            'type' => $type,
            'dedupe' => "{$id}|{$topic}",
            'payload' => base64_encode($body),
        ], $topics);

        $problem = null;

        // One more try after a hiccup, with the same dedupe ids, so Flow ignores an event that did arrive
        foreach ([1, 2] as $attempt) {
            try {
                $response = Http::withToken((string) self::secret('app_token'))
                    ->acceptJson()
                    ->connectTimeout(1)
                    ->timeout((int) config('flow.timeout'))
                    ->post(config('flow.url').'/radiance.flow.v1.Events/PublishBatch', ['events' => $events]);

                $problem = $response->failed() ? "Flow answered {$response->status()}" : null;

                if (! $response->serverError()) {
                    break;
                }
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }

            if ($attempt === 1) {
                usleep(100_000);
            }
        }

        if ($problem !== null) {
            self::failed($problem);
        }
    }

    private static function failed(string $why): void
    {
        Log::warning("Live update not sent: {$why}");
        Cache::put(self::PAUSE_KEY, true, (int) config('flow.pause_after_failure'));
    }

    /**
     * A secret from its file (Flow's secret store writes it), else the env value.
     */
    public static function secret(string $name): ?string
    {
        $file = config("flow.{$name}_file");
        $value = $file && is_readable($file) ? file_get_contents($file) : config("flow.{$name}");
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The WebSocket the browser opens.
     */
    public static function socketUrl(): string
    {
        return config('flow.ws_url') ?: preg_replace('/^http/', 'ws', (string) config('flow.url')).'/ws';
    }
}
