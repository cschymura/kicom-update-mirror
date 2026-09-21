<?php
declare(strict_types=1);

function kicomNormalSessionLockFile(string $sessionId): string {
    return kicomAuthSessionsDir().'/'.$sessionId.'.normal.lock';
}
