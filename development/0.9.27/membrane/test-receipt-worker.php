<?php
declare(strict_types=1);
/**
 * STAGING test worker. A separate process exits immediately after durable
 * reservation, before any external effect. Parent must reconcile, never send.
 */
require_once __DIR__.'/KiComMembraneEventReceipt.php';
$path=$argv[1]??'';
$id=$argv[2]??'';
$raw=$argv[3]??'';
$receipt=new KiComMembraneEventReceipt($path);
$r=$receipt->reserve($id,$raw);
if (($r['code']??null)!=='NEW_DURABLE_RECEIPT') exit(7);
// Deliberate process termination, with no Slack/SMTP/effect operation.
exit(0);
