<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramCanonicalMutationService.php';
final class KiComEngramCanonicalMcpMutationController {
 private KiComEngramCanonicalMutationService $mutations;
 public function __construct(KiComEngramCanonicalMutationService $mutations){$this->mutations=$mutations;}
 public function handle(array $oauth,array $request,int $now):array{
  $tool=$request['tool']??null;
  if(!is_string($tool)||!in_array($tool,['engram_write','engram_update','engram_archive'],true))throw new RuntimeException('unsupported MCP mutation tool');
  if(($oauth['authenticated']??false)!==true)throw new RuntimeException('authenticated OAuth context required');
  $scopes=$oauth['scopes']??[];if(!is_array($scopes)||!in_array('engram.write',$scopes,true))throw new RuntimeException('engram.write scope required');
  foreach(['owner','namespace','connector_id','token_fingerprint'] as $k)if(!isset($oauth[$k])||!is_string($oauth[$k])||$oauth[$k]==='')throw new RuntimeException('incomplete OAuth context');
  foreach(['owner','namespace','oauth','scope','scopes'] as $k)if(array_key_exists($k,$request))throw new RuntimeException('MCP identity or scope claims forbidden');
  $grant=$request['write_grant']??null;$idem=$request['idempotency_key']??null;$input=$request['input']??null;
  if(!is_string($grant)||!is_string($idem)||!is_array($input))throw new InvalidArgumentException('invalid MCP mutation envelope');
  return $this->mutations->mutate($grant,$oauth,$tool,$input,$idem,$now);
 }
}
