<?php
declare(strict_types=1);
// This test exercises the actual DEV84 native class' readiness method
// with a PDO test double; it is NOT a live PDO-SQLite/HTTP integration test.
// Set KICOM_DEV84_NATIVE_FILE to the exact DEV82+DEV84 staged source.
$path=getenv('KICOM_DEV84_NATIVE_FILE');
if(!is_string($path)||$path===''||!is_file($path))throw new RuntimeException('Set KICOM_DEV84_NATIVE_FILE to verified native source');
require $path;
class Dev84Result extends PDOStatement {
    public function __construct(private mixed $answer){}
    public function fetchColumn(int $column=0):mixed{return $this->answer;}
}
class Dev84Database extends PDO {
    public array $queries=[];
    public function __construct(public bool $exists,public bool $malformed=false){}
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs):PDOStatement|false {
        $this->queries[]=$query;
        if(str_contains($query,'sqlite_master'))return new Dev84Result($this->exists?'mirage_oauth_refresh_tokens':false);
        if($this->malformed)throw new PDOException('no such column: parent_access_hash');
        return new Dev84Result(false);
    }
}
$n=0;function ok(bool $pass,string $label):void{global $n;if(!$pass)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
$m=new ReflectionMethod(KiComEngramOAuthTransactions::class,'refreshSchemaReady');
$old=new Dev84Database(false);
ok($m->invoke(null,$old)===false,'missing table uses legacy read path');
ok(count($old->queries)===1,'no DDL or refresh-table query on missing schema');
$new=new Dev84Database(true);
ok($m->invoke(null,$new)===true,'migrated schema enables refresh');
ok(count($new->queries)===2,'migrated schema checked for necessary columns');
$broken=new Dev84Database(true,true);$caught=false;
try{$m->invoke(null,$broken);}catch(PDOException){$caught=true;}
ok($caught,'malformed existing table fails closed');
$s=file_get_contents($path);
ok(substr_count($s,'if (!self::refreshSchemaReady($db))')===1,'one fallback inside native exchange');
ok(str_contains($s,"\$db->exec('COMMIT');"),'legacy read remains committed');
ok(str_contains($s,"\$db->exec('ROLLBACK');"),'error rollback retained');
echo "DEV84_ASSERTIONS=$n\n";
