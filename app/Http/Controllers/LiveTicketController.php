<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\User;
use App\Support\Live\FlowLive;
use App\Support\Live\Ticket;
use App\Support\Live\Topics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Hands the signed-in browser (a team member in /admin, or a customer on the
 * site or in the app) a short-lived ticket for Flow's WebSocket, listing only
 * the topics that person may read. 503 while live updates are not set up:
 * the screens then keep their own refresh.
 */
class LiveTicketController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $guest = Auth::guard('guest')->user();
        $secret = FlowLive::secret('ticket_secret');

        if ($secret === null) {
            return response()->json(['message' => 'Live updates are off.'], 503)->header('Cache-Control', 'no-store');
        }

        $topics = [];
        $subject = null;

        if ($user instanceof User) {
            $topics = Topics::forUser($user);
            $subject = "user:{$user->id}";
        }

        if ($guest instanceof Guest) {
            $topics = [...$topics, ...Topics::forGuest($guest)];
            $subject ??= "guest:{$guest->id}";
        }

        if ($subject === null) {
            return response()->json(['message' => 'Sign in to continue.', 'signIn' => true], 401)->header('Cache-Control', 'no-store');
        }

        $ttl = (int) config('flow.ticket_ttl');

        return response()->json([
            'ticket' => Ticket::sign($topics, $subject, time() + $ttl, $secret),
            'topics' => $topics,
            'expiresIn' => $ttl,
            'url' => FlowLive::socketUrl(),
        ])->header('Cache-Control', 'no-store');
    }
}
