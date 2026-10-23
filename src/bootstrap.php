<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
if (is_file(ROOT . '/vendor/autoload.php')) require ROOT . '/vendor/autoload.php';
$envFile=in_array(PHP_SAPI,['cli','cli-server'],true)&&getenv('MATTE_ENV_FILE')?getenv('MATTE_ENV_FILE'):ROOT.'/.env';
$GLOBALS['matteConfig']=[];
if (is_file($envFile)) foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if (!$line || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $key=trim($key);
    // Keep .env values local to this request: putenv is shared by Apache's
    // Windows threads and another request can restore it during authentication.
    $external=getenv($key);
    $GLOBALS['matteConfig'][$key]=$external===false?trim(trim($value), "\"'"):$external;
}
function env(string $key, string $default = ''): string { if(array_key_exists($key,$GLOBALS['matteConfig']))return $GLOBALS['matteConfig'][$key];$v=getenv($key);return $v===false?$default:$v; }
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . env('DB_HOST','127.0.0.1') . ';port=' . env('DB_PORT','3306') . ';dbname=' . env('DB_NAME','matte_php') . ';charset=utf8mb4', env('DB_USER','root'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET time_zone='+00:00'");
    }
    return $pdo;
}
function sql(string $query, array $args=[]): PDOStatement { $s=db()->prepare($query); foreach(array_values($args) as $i=>$v) $s->bindValue($i+1, $v, is_int($v)?PDO::PARAM_INT:($v===null?PDO::PARAM_NULL:(is_bool($v)?PDO::PARAM_BOOL:PDO::PARAM_STR))); $s->execute(); return $s; }
function rows(string $q,array $a=[]): array { return sql($q,$a)->fetchAll(); }
function one(string $q,array $a=[]): ?array { return sql($q,$a)->fetch() ?: null; }
function tx(callable $fn): mixed {
    static $serial=0;
    $nested=db()->inTransaction();$savepoint='matte_tx_'.++$serial;
    if($nested)db()->exec('SAVEPOINT '.$savepoint);else db()->beginTransaction();
    try{$r=$fn();if($nested)db()->exec('RELEASE SAVEPOINT '.$savepoint);else db()->commit();return $r;}
    catch(Throwable $e){if(db()->inTransaction()){if($nested)db()->exec('ROLLBACK TO SAVEPOINT '.$savepoint);else db()->rollBack();}throw $e;}
}
function insert(string $table,array $values): int { $keys=array_keys($values); sql('INSERT INTO `'.$table.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($values)); return (int)db()->lastInsertId(); }
function update(string $table,array $values,string $where,array $args): void { sql('UPDATE `'.$table.'` SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($values))).' WHERE '.$where,[...array_values($values),...$args]); }
class HttpError extends RuntimeException { public function __construct(string $message,public int $status=400,public array $extra=[]) {parent::__construct($message);} }
function fail(string $m,int $s=400,array $extra=[]): never { throw new HttpError($m,$s,$extra); }
function json(mixed $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; }
function clean(mixed $s,int $n=200): string { return is_string($s)?mb_substr(trim(preg_replace('/\s+/u',' ',$s)),0,$n):''; }
function textValue(mixed $s,int $n=4000): string {return is_string($s)?mb_substr(trim(str_replace("\r",'',$s)),0,$n):'';}
function num(mixed $v,int $default=0): int {return filter_var($v,FILTER_VALIDATE_INT)!==false?(int)$v:$default;}
function slug(string $s): string {return substr(trim(preg_replace('/[^a-z0-9]+/','-',strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s))),'-'),0,80);}
function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function isUuid(mixed $s): bool {return is_string($s)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$s)===1;}
function b64(string $s): string {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function jwtSecret(): string {$s=env('JWT_SECRET');if(strlen($s)<32)fail('Configure JWT_SECRET with at least 32 characters.',503);return $s;}
function tokenFor(array $u,string $role): array { $user=['id'=>(int)$u['id'],'email'=>$u['email'],'name'=>$u['name'],'role'=>$role]; $h=b64('{"alg":"HS256","typ":"JWT"}');$p=b64(json_encode(['sub'=>$user['id'],'email'=>$user['email'],'name'=>$user['name'],'role'=>$role,'iat'=>time(),'exp'=>time()+($role==='admin'?43200:2592000)]));return ['token'=>$h.'.'.$p.'.'.b64(hash_hmac('sha256',$h.'.'.$p,jwtSecret(),true)),'user'=>$user]; }
function auth(?string $role=null,bool $optional=false): ?array {
    $h=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!$h&&function_exists('getallheaders'))foreach(getallheaders() as $key=>$value)if(strcasecmp($key,'Authorization')===0){$h=$value;break;}
    if(!$h&&$optional)return null;
    $parts=explode('.',str_starts_with($h,'Bearer ')?substr($h,7):'');
    if(count($parts)!==3)fail('Please sign in again.',401);
    [$a,$b,$c]=$parts;$head=json_decode(base64_decode(strtr($a,'-_','+/')),true);$p=json_decode(base64_decode(strtr($b,'-_','+/')),true);
    if(($head['alg']??'')!=='HS256'||!hash_equals(b64(hash_hmac('sha256',$a.'.'.$b,jwtSecret(),true)),$c)||!is_int($p['sub']??null)||$p['sub']<1||($p['exp']??0)<=time()||!in_array($p['role']??'',['admin','customer'],true)||($role&&$p['role']!==$role))fail('Please sign in again.',401);
    $table=$p['role']==='admin'?'admin_users':'customer_users';if(!one("SELECT id FROM $table WHERE id=?",[$p['sub']]))fail('Please sign in again.',401);return $p;
}
function rate(string $key,int $limit=30,int $seconds=600): void {
    $key=hash('sha256',$key.'|'.($_SERVER['REMOTE_ADDR']??'cli').'|'.intdiv(time(),$seconds));
    sql('INSERT INTO rate_limits (bucket,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$key,gmdate('Y-m-d H:i:s',time()+$seconds)]);
    if((int)one('SELECT hits FROM rate_limits WHERE bucket=?',[$key])['hits']>$limit){header('Retry-After: '.$seconds);fail('Too many requests. Please try again later.',429);}
}
function pin(array $b): array {
    $lat=$b['latitude']??null;$lng=$b['longitude']??null;if($lat===''||$lat===null)$lat=null;if($lng===''||$lng===null)$lng=null;
    if($lat===null&&$lng===null)return ['latitude'=>null,'longitude'=>null];
    if(!is_numeric($lat)||!is_numeric($lng)||abs((float)$lat)>90||abs((float)$lng)>180)fail('Enter valid latitude and longitude.');
    return ['latitude'=>(float)$lat,'longitude'=>(float)$lng];
}
function pageResult(array $items,array $query=[],string $key='items'): array {
    $page=max(1,min(100000,num($query['page']??1,1)));$size=max(10,min(100,num($query['pageSize']??25,25)));$total=count($items);
    return [$key=>array_slice($items,($page-1)*$size,$size),'page'=>$page,'pageSize'=>$size,'total'=>$total,'totalPages'=>max(1,(int)ceil($total/$size)),'hasMore'=>$page*$size<$total];
}
function contains(string $s,string $q): bool {return $q===''||mb_stripos($s,$q)!==false;}
function e(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
