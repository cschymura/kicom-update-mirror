<?php
declare(strict_types=1);
require_once __DIR__.'/../dev72/KiComEngramWriteGrant.php';
require_once __DIR__.'/../dev75/KiComEngramRevisionAdapter.php';

/** DEV-76: compose server-signed write authorization with canonical revision storage. */
final class KiComEngramCanonicalMutationService {
    private KiComEngramWriteGrant $grants;
    private KiComEngramRevisionAdapter $store;
    /** @var array<string,bool> */
    private array $usedNonces=[];
    public function __construct(KiComEngramWriteGrant $grants, KiComEngramRevisionAdapter $store){$this->grants=$grants;$this->store=$store;}
    public function mutate(string $grant,array $oauth,string $operation,array $input,string $idempotencyKey,int $now):array {
        $claims=$this->grants->verify($grant,$oauth,$operation,$now);
        $nonceKey=$claims['owner']."\0".$claims['namespace']."\0".$claims['nonce'];
        if(isset($this->usedNonces[$nonceKey])) throw new RuntimeException('write grant nonce replay');
        // Mark only after authorization succeeded; idempotency belongs to the SQLite adapter.
        $result=$this->store->mutate($claims['owner'],$claims['namespace'],$operation,$input,$idempotencyKey);
        $this->usedNonces[$nonceKey]=true;
        return $result;
    }
}
