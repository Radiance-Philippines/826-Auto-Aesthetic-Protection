<?php

namespace App\Http\Controllers;

use App\Support\ConsoleUrl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The React admin's bell: the same stored notifications Filament writes and
 * shows (format "filament"), only ever the signed-in person's own. Their
 * stored links open the new screens; the rows themselves are not rewritten.
 */
class ConsoleNotificationController extends Controller
{
    private const PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $limit = self::PAGE * max(1, min(20, $request->integer('page', 1)));
        $rows = $this->mine($request)->limit($limit + 1)->get();

        return response()->json([
            'data' => $rows->take($limit)->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'title' => (string) ($notification->data['title'] ?? ''),
                'body' => (string) ($notification->data['body'] ?? ''),
                'status' => $notification->data['status'] ?? null,
                'icon' => $notification->data['icon'] ?? null,
                'read' => $notification->read_at !== null,
                'ago' => $notification->created_at->diffForHumans(),
                'actions' => collect($notification->data['actions'] ?? [])
                    ->map(fn (array $action) => [
                        'label' => (string) ($action['label'] ?? $action['name'] ?? 'Open'),
                        'url' => ConsoleUrl::from($action['url'] ?? null),
                        'marksRead' => (bool) ($action['shouldMarkAsRead'] ?? false),
                        'newTab' => (bool) ($action['shouldOpenUrlInNewTab'] ?? false),
                    ])
                    ->filter(fn (array $action) => $action['url'] !== null)
                    ->values()
                    ->all(),
            ])->values()->all(),
            'meta' => ['unread' => $this->mine($request)->unread()->count(), 'more' => $rows->count() > $limit],
        ])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, string $id): Response
    {
        $this->mine($request)->whereKey($id)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    public function readAll(Request $request): Response
    {
        $this->mine($request)->unread()->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->mine($request)->whereKey($id)->firstOrFail()->delete();

        return response()->noContent();
    }

    public function clear(Request $request): Response
    {
        $this->mine($request)->delete();

        return response()->noContent();
    }

    private function mine(Request $request): Relation
    {
        return $request->user()->notifications()->where('data->format', 'filament');
    }
}
