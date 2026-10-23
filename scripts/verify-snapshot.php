<?php
// Compare every source column and record against the imported MariaDB database.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
function canonicalJson(mixed $value): mixed {if(is_object($value)){$vars=get_object_vars($value);ksort($vars);$o=new stdClass();foreach($vars as $k=>$v)$o->$k=canonicalJson($v);return $o;}if(is_array($value))return array_map('canonicalJson',$value);return $value;}
function normal(mixed $v,string $type,bool $json=false): mixed {
    if($v===null)return null;
    if($json){if(is_string($v))$v=json_decode($v,false,512,JSON_THROW_ON_ERROR);return json_encode(canonicalJson($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    if(str_starts_with($type,'datetime'))return (new DateTimeImmutable((string)$v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    if(preg_match('/^(tinyint|smallint|int|bigint)/',$type))return (string)(int)$v;
    if(preg_match('/^(decimal|double|float)/',$type))return sprintf('%.8F',(float)$v);
    return (string)$v;
}
try{
    $file=$argv[1]??ROOT.'/storage/postgres-snapshot.json';if(!is_file($file.'.sha256')||!hash_equals(trim(file_get_contents($file.'.sha256')),hash_file('sha256',$file)))throw new RuntimeException('Snapshot checksum mismatch.');
    $snapshot=json_decode(file_get_contents($file),false,512,JSON_THROW_ON_ERROR);if($snapshot->format!=='matte-postgres-v1')throw new RuntimeException('Unknown snapshot format.');
    $allowed=['categories','products','product_images','admin_users','customer_users','orders','order_items','conversations','messages','customer_carts','quote_requests','site_content','momo_payments','whatsapp_conversations','whatsapp_messages','catalog_migrations'];$counts=[];$fields=0;
    foreach($snapshot->tables as $table=>$source){if(!in_array($table,$allowed,true))throw new RuntimeException('Unmapped table.');$columns=[];$primary=[];foreach(rows('SHOW COLUMNS FROM `'.$table.'`') as $c){$columns[$c['Field']]=$c['Type'];if($c['Key']==='PRI')$primary[]=$c['Field'];}if(!$primary)throw new RuntimeException('Missing primary key: '.$table);
        $actual=rows('SELECT * FROM `'.$table.'`');if(count($actual)!==count($source))throw new RuntimeException('Count mismatch: '.$table);$byKey=[];foreach($actual as $r)$byKey[json_encode(array_map(fn($k)=>(string)$r[$k],$primary))]=$r;
        foreach($source as $record){$r=(array)$record;$key=json_encode(array_map(fn($k)=>(string)$r[$k],$primary));$got=$byKey[$key]??null;if(!$got)throw new RuntimeException('Missing record: '.$table.' '.$key);
            foreach($r as $col=>$v){if(!isset($columns[$col]))throw new RuntimeException('Unmapped column: '.$table.'.'.$col);$isJson=in_array($table.'.'.$col,['products.specs','site_content.content','customer_carts.items'],true);if(normal($v,$columns[$col],$isJson)!==normal($got[$col],$columns[$col],$isJson))throw new RuntimeException('Value mismatch: '.$table.'.'.$col.' at '.$key);$fields++;}
        }$counts[$table]=count($source);
    }
    if((int)one('SELECT next_value FROM invoice_counter WHERE id=1')['next_value']<(int)$snapshot->sequence)throw new RuntimeException('Invoice numbering would go backwards.');
    $report=['verifiedAt'=>gmdate(DATE_ATOM),'snapshotSha256'=>hash_file('sha256',$file),'rows'=>$counts,'fieldsCompared'=>$fields,'result'=>'pass'];file_put_contents(ROOT.'/storage/migration-verification.json',json_encode($report,JSON_PRETTY_PRINT));echo 'PASS: '.array_sum($counts).' rows and '.$fields." source fields verified.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
