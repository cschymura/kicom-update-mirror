<?php
declare(strict_types=1);
require_once __DIR__.'/ArtifactChannelResolver.php';

function t(bool $ok,string $label): void { if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "OK $label\n"; }

$h1=str_repeat('a',64);$h2=str_repeat('b',64);
$sources=[
    ['id'=>'primary','type'=>'https-channel','priority'=>10,'enabled'=>true,'url'=>'https://updates.example/channel.json','allowed_hosts'=>['updates.example'],'package_hosts'=>['cdn.example']],
    ['id'=>'mirror','type'=>'https-channel','priority'=>20,'enabled'=>true,'url'=>'https://mirror.example/channel.json','allowed_hosts'=>['mirror.example'],'package_hosts'=>['mirror.example']],
    ['id'=>'old','type'=>'https-channel','priority'=>30,'enabled'=>true,'url'=>'https://old.example/channel.json','allowed_hosts'=>['old.example'],'package_hosts'=>['old.example']],
];
$payloads=[
    'https://updates.example/channel.json'=>json_encode(['releases'=>[['version'=>'0.9.17','sha256'=>$h1,'filename'=>'KiCom-0.9.17.zip','url'=>'https://cdn.example/KiCom-0.9.17.zip']]],JSON_UNESCAPED_SLASHES),
    'https://mirror.example/channel.json'=>json_encode(['version'=>'0.9.17','sha256'=>$h1,'filename'=>'KiCom-0.9.17.zip','download_url'=>'https://mirror.example/KiCom-0.9.17.zip'],JSON_UNESCAPED_SLASHES),
    'https://old.example/channel.json'=>json_encode(['version'=>'0.9.15','sha256'=>$h2,'filename'=>'KiCom-0.9.15.zip','download_url'=>'https://old.example/KiCom-0.9.15.zip'],JSON_UNESCAPED_SLASHES),
];
$fetcher=static function(string $url,int $max,array $hosts)use($payloads): array {
    if(!isset($payloads[$url]))return ['ok'=>false,'code'=>'TEST_MISSING'];
    return ['ok'=>true,'body'=>(string)$payloads[$url]];
};
$r=(new KiComArtifactChannelResolver($sources,$fetcher))->discoverLatest('0.9.15');
t(!empty($r['ok'])&&($r['code']??'')==='ARTIFACT_RELEASE_DISCOVERED','discovers newest release');
t(($r['artifact']['version']??'')==='0.9.17'&&($r['artifact']['sha256']??'')===$h1,'binds version and sha');
t(count($r['evidence_sources']??[])===2,'retains agreeing evidence sources');

$conflictPayloads=$payloads;
$conflictPayloads['https://mirror.example/channel.json']=json_encode(['version'=>'0.9.17','sha256'=>$h2,'filename'=>'KiCom-0.9.17.zip','download_url'=>'https://mirror.example/KiCom-0.9.17.zip'],JSON_UNESCAPED_SLASHES);
$conflictFetcher=static function(string $url,int $max,array $hosts)use($conflictPayloads): array { return isset($conflictPayloads[$url])?['ok'=>true,'body'=>(string)$conflictPayloads[$url]]:['ok'=>false,'code'=>'TEST_MISSING']; };
$c=(new KiComArtifactChannelResolver($sources,$conflictFetcher))->discoverLatest('0.9.15');
t(empty($c['ok'])&&($c['code']??'')==='ARTIFACT_CHANNEL_HASH_CONFLICT','fails closed on same-version hash conflict');

$n=(new KiComArtifactChannelResolver($sources,$fetcher))->discoverLatest('0.9.17');
t(!empty($n['ok'])&&($n['code']??'')==='ARTIFACT_NO_UPDATE','ignores current and older releases');

$badSources=[['id'=>'bad','type'=>'https-channel','priority'=>1,'enabled'=>true,'url'=>'https://bad.example/channel.json','allowed_hosts'=>['bad.example'],'package_hosts'=>['good.example']]];
$badFetcher=static fn(string $url,int $max,array $hosts):array=>['ok'=>true,'body'=>json_encode(['version'=>'1.0.0','sha256'=>str_repeat('c',64),'filename'=>'KiCom-1.0.0.zip','download_url'=>'https://evil.example/KiCom-1.0.0.zip'])];
$b=(new KiComArtifactChannelResolver($badSources,$badFetcher))->discoverLatest('0.9.15');
t(!empty($b['ok'])&&($b['code']??'')==='ARTIFACT_NO_UPDATE','rejects package host outside source allowlist');

echo "ARTIFACT_CHANNEL_RESOLVER_SELFTEST_OK\n";
