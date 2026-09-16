<?php
declare(strict_types=1);

/* Compatibility bridge used after the original live consumer is renamed to
 * kicomAutonomySessionConsumeLegacyV5(). Legacy callers remain on the existing
 * path unless a deferred resilient client_request_id is active. */
function kicomAutonomySessionConsumeBridgeV1(string $id,string $token): array {
    $requestId=function_exists('kicomDeferredGuardClientRequestIdV1')?kicomDeferredGuardClientRequestIdV1():'';
    if($requestId!=='')return kicomAutonomySessionConsumeResilientV3($id,$token,$requestId);
    return kicomAutonomySessionConsumeLegacyV5($id,$token);
}
