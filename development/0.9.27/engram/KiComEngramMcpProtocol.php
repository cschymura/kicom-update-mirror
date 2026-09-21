<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramMcpRuntimeGate.php';
require_once __DIR__.'/KiComEngramMcpJsonAdapter.php';

/**
 * DEV-46: stateless MCP JSON-RPC 2.0 protocol layer for a FUTURE authenticated
 * HTTPS POST route. No PHP route or token issuer is provided by this class.
 *
 * The trusted server wrapper must supply server-owned runtime, ACTIVE
 * activation DB, CURRENT owner registry, authenticated connector identity,
 * and a store/adapter factory. All requests, even initialize/tools/list, are
 * authenticated BEFORE their method or capabilities are disclosed.
 *
 * One read-only tool, engram_search. No write tool or conversational ingestion.
 * This protocol layer cannot turn on a disabled 0.9.29/0.9.30 host.
 */
final class KiComEngramMcpProtocol
{
    private const VERSION='2025-06-18';
    private const MAX_REQUEST=8192;
    private const MAX_RESPONSE=16384;
    private const HEADERS=[
        'Content-Type'=>'application/json; charset=utf-8',
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
    ];

    public static function handle(
        string $raw,
        array $runtime,
        PDO $activationDb,
        callable $lookupOwner,
        array $verifiedConnector,
        int $now,
        callable $adapterFactory
    ): array {
        // Inactive or unauthenticated clients may not enumerate tools,
        // initialize a session, or learn whether a memory database exists.
        try {
            KiComEngramMcpRuntimeGate::authorize(
                $runtime,$activationDb,$lookupOwner,$verifiedConnector
            );
        } catch (Throwable) {
            return self::response(404,'');
        }
        if ($raw==='' || strlen($raw)>self::MAX_REQUEST) {
            return self::fault(-32600,'Invalid Request',null);
        }
        try {
            $req=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($req) || array_is_list($req)
                || !self::keys($req,['jsonrpc','method'],['id','params'])
                || ($req['jsonrpc']??null)!=='2.0'
                || !is_string($req['method']??null)) {
                return self::fault(-32600,'Invalid Request',null);
            }
            $method=$req['method'];
            // MCP notification response is HTTP 202, not a JSON-RPC object.
            if ($method==='notifications/initialized' && !array_key_exists('id',$req)
                && (!array_key_exists('params',$req)
                    || $req['params']===[])) {
                return self::response(202,'');
            }
            $id=$req['id']??null;
            if (!(is_int($id) || (is_string($id) && strlen($id)<=128))
                || is_bool($id)) {
                return self::fault(-32600,'Invalid Request',null);
            }
            $params=$req['params']??[];
            if (!is_array($params) || array_is_list($params) && $params!==[]) {
                return self::fault(-32602,'Invalid params',$id);
            }
            if ($method==='initialize') {
                if (!self::keys($params,['protocolVersion','capabilities','clientInfo'])
                    || !is_string($params['protocolVersion'])
                    || !is_array($params['capabilities'])
                    || !is_array($params['clientInfo'])
                    || !isset($params['clientInfo']['name'],$params['clientInfo']['version'])
                    || !is_string($params['clientInfo']['name'])
                    || !is_string($params['clientInfo']['version'])) {
                    return self::fault(-32602,'Invalid params',$id);
                }
                return self::success($id,[
                    'protocolVersion'=>self::VERSION,
                    'capabilities'=>['tools'=>['listChanged'=>false]],
                    'serverInfo'=>['name'=>'mirage-engram','version'=>'0.1.0'],
                    'instructions'=>'Only authorized, bounded, read-only retrieval.',
                ]);
            }
            if ($method==='ping' && $params===[]) return self::success($id,[]);
            if ($method==='tools/list') {
                if ($params!==[]) return self::fault(-32602,'Invalid params',$id);
                return self::success($id,[
                    'tools'=>[[
                        'name'=>'engram_search',
                        'description'=>'Find up to three explicitly authorized project memories in private KiCom Engram.',
                        'securitySchemes'=>[['type'=>'oauth2','scopes'=>['engram.read']]],
                        'inputSchema'=>[
                            'type'=>'object',
                            'properties'=>[
                                'query'=>['type'=>'string','minLength'=>1,'maxLength'=>128],
                                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>3],
                            ],
                            'required'=>['query'],
                            'additionalProperties'=>false,
                        ],
                    ]],
                ]);
            }
            if ($method!=='tools/call') {
                return self::fault(-32601,'Method not found',$id);
            }
            if (!self::keys($params,['name','arguments'])
                || $params['name']!=='engram_search'
                || !is_array($params['arguments'])
                || array_is_list($params['arguments'])) {
                return self::fault(-32602,'Invalid params',$id);
            }
            $args=$params['arguments'];
            if (!self::keys($args,['query'],['limit'])
                || !is_string($args['query'])
                || $args['query']==='' || strlen($args['query'])>128
                || (array_key_exists('limit',$args)
                    && (!is_int($args['limit']) || $args['limit']<1 || $args['limit']>3))) {
                return self::fault(-32602,'Invalid params',$id);
            }

            // This internal envelope is constructed entirely from server-side
            // identity and policy; JSON-RPC callers CANNOT select owner/space.
            $internal=json_encode([
                'v'=>1,'op'=>'read','owner'=>'mirage-owner','namespace'=>'project',
                'nonce'=>bin2hex(random_bytes(16)),'issued_at'=>$now,
                'limit'=>$args['limit']??3,'query'=>$args['query'],
            ],JSON_THROW_ON_ERROR);
            $result=KiComEngramMcpRuntimeGate::dispatch(
                $internal,$runtime,$activationDb,$lookupOwner,$verifiedConnector,
                $now,$adapterFactory
            );
            $data=json_decode($result,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['ok']??null)!==true
                || !is_array($data['result']['items']??null)) {
                return self::success($id,['isError'=>true,
                    'content'=>[['type'=>'text','text'=>'Engram retrieval unavailable']]]);
            }
            $items=$data['result']['items'];
            if (count($items)>($args['limit']??3)) {
                return self::success($id,['isError'=>true,
                    'content'=>[['type'=>'text','text'=>'Engram retrieval unavailable']]]);
            }
            $text=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($text)>8192) {
                return self::success($id,['isError'=>true,
                    'content'=>[['type'=>'text','text'=>'Engram retrieval unavailable']]]);
            }
            return self::success($id,[
                'content'=>[['type'=>'text','text'=>$text]],
                'isError'=>false,
            ]);
        } catch (JsonException) {
            return self::fault(-32700,'Parse error',null);
        } catch (Throwable) {
            return self::fault(-32603,'Internal error',null);
        }
    }

    private static function keys(array $x,array $required,array $optional=[]): bool
    {
        foreach($required as $key)if(!array_key_exists($key,$x))return false;
        foreach(array_keys($x) as $key)if(!in_array($key,$required,true)
            && !in_array($key,$optional,true))return false;
        return true;
    }

    private static function response(int $status,string $body): array
    {
        if (strlen($body)>self::MAX_RESPONSE) return self::fault(-32603,'Internal error',null);
        return ['http_status'=>$status,'headers'=>self::HEADERS,'body'=>$body];
    }

    private static function fault(int $code,string $message,int|string|null $id): array
    {
        return self::response(200,json_encode([
            'jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message],
        ],JSON_THROW_ON_ERROR));
    }

    private static function success(int|string $id,array $result): array
    {
        return self::response(200,json_encode([
            'jsonrpc'=>'2.0','id'=>$id,'result'=>$result,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
}
