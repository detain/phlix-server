<?php

/**
 * Compatibility shim — the WebSocketEvents class moved to
 * WebSocketEvents.php to comply with PSR-4 (the class name must match the
 * filename). This file is kept only because phpstan.neon.dist references
 * its old path in `excludePaths`; the actual definition lives at
 * {@see \Phlex\Server\WebSocket\WebSocketEvents} in WebSocketEvents.php.
 *
 * Loading this file is harmless (no class is defined here) and lets the
 * existing phpstan exclude entry resolve until the next config refresh.
 */

declare(strict_types=1);
