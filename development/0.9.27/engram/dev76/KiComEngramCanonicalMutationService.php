<?php
declare(strict_types=1);
require_once __DIR__.'/../dev72/KiComEngramWriteGrant.php';
require_once __DIR__.'/../dev75/KiComEngramRevisionAdapter.php';

/** DEV-76: compose server-signed write authorization with canonical revision storage. */
final class KiComEngramCanonicalMutationService {
    private KiComEngramWriteGrant $grants;
    private KiComEngramRevisionAdapter $store;
    public function __construct(KiComEngramWriteGrant $grants, KiComEngramRevisionAdapter $store){$this->grants=$grants;$this->store=$store;}
    public function mutate(string $grant,array $oauth,string $operation,array $input,string $idempotencyKey,int $now):array {
        $claims=$this->grants->verify($grant,$oauth,$operation,$now);
        // Durable nonce consumption occurs atomically with receipt and revision.
        // The same grant+request idempotently retries; a different request cannot
        // consume the grant again, even in another PHP process.
        return $this->store->mutate($claims['owner'],$claims['namespace'],$operation,$input,$idempotencyKey,$claims['nonce']);
    }
}
