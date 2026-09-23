<?php
declare(strict_types=1);
require_once __DIR__.'/../dev72/KiComEngramWriteGrant.php';
require_once __DIR__.'/../dev75/KiComEngramRevisionAdapter.php';
require_once __DIR__.'/../dev88/KiComEngramVerifiedWriteProvenance.php';

/** DEV-76: compose server-signed write authorization with canonical revision storage. */
final class KiComEngramCanonicalMutationService {
    private KiComEngramWriteGrant $grants;
    private KiComEngramRevisionAdapter $store;
    private $trustedReceiptLookup;
    public function __construct(KiComEngramWriteGrant $grants, KiComEngramRevisionAdapter $store,?callable $trustedReceiptLookup=null){$this->grants=$grants;$this->store=$store;$this->trustedReceiptLookup=$trustedReceiptLookup;}
    public function mutate(string $grant,array $oauth,string $operation,array $input,string $idempotencyKey,int $now):array {
        $claims=$this->grants->verify($grant,$oauth,$operation,$now);
        $provenance=KiComEngramVerifiedWriteProvenance::resolve($oauth,$this->trustedReceiptLookup);
        // Durable nonce consumption occurs atomically with receipt and revision.
        // The same grant+request idempotently retries; a different request cannot
        // consume the grant again, even in another PHP process.
        return $this->store->mutate($claims['owner'],$claims['namespace'],$operation,$input,$idempotencyKey,$claims['nonce'],$provenance);
    }
}
