<?php

namespace App\Support\Inbox;

use RuntimeException;

/**
 * A reply the inbox can't send, or a Meta call that failed. The message is
 * safe to show in /admin and to store: it never holds a token, a secret or
 * a full URL.
 */
class InboxException extends RuntimeException {}
