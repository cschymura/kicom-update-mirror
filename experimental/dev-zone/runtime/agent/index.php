<?php
declare(strict_types=1);

/*
 * Extensionless/directory entry point for GET-based development agents.
 * Some extraction transports refuse direct .php URLs but can fetch a
 * directory index. The actual authority and routing remain in dev-bridge.php.
 */
require dirname(__DIR__).'/dev-bridge.php';
