<?php
// Import ONLY into an empty target schema. Unknown source columns abort the import.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
try{
    $path=$argv[1]??ROOT.'/storage/postgres-snapshot.json';
    if(!is_file($path)||!is_file($path.'.sha256'))throw new RuntimeException('Snapshot and checksum files are required.');
    if(!hash_equals(trim(file_get_contents($path.'.sha256')),hash_file('sha256',$path)))throw new RuntimeException('Snapshot checksum mismatch.');
    $snapshot=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$objectSnapshot=json_decode(file_get_contents($path),false,512,JSON_THROW_ON_ERROR);if(($snapshot['format']??'')!=='matte-postgres-v1')throw new RuntimeException('Unknown snapshot format.');
    $order=['categories','products','product_images','admin_users','customer_users','orders','order_items','conversations','messages','customer_carts','quote_requests','site_content','momo_payments','whatsapp_conversations','whatsapp_messages','catalog_migrations'];
    if(array_diff(array_keys($snapshot['tables']),$order))throw new RuntimeException('Snapshot includes unmapped tables.');
    $report=[];
    tx(function()use($snapshot,$objectSnapshot,$order,&$report){
        foreach($order as $table){if((int)one('SELECT COUNT(*) AS n FROM `'.$table.'`')['n']!==0)throw new RuntimeException('Target table '.$table.' is not empty. Use a new database; existing data will not be overwritten.');}
        foreach($order as $table){$source=$snapshot['tables'][$table]??[];$columns=[];foreach(rows('SHOW COLUMNS FROM `'.$table.'`') as $c)$columns[$c['Field']]=$c['Type'];$parents=[];
            foreach($source as $index=>$r){if(array_diff(array_keys($r),array_keys($columns)))throw new RuntimeException('Unmapped columns in '.$table.': '.implode(',',array_diff(array_keys($r),array_keys($columns))));
                foreach($r as $k=>&$v){if(in_array($table.'.'.$k,['products.specs','site_content.content','customer_carts.items'],true))$v=json_encode($objectSnapshot->tables->{$table}[$index]->{$k},JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);elseif(is_array($v)||is_object($v))$v=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($v!==null&&str_starts_with($columns[$k],'datetime'))$v=(new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');if(is_bool($v))$v=(int)$v;}unset($v);
                if($table==='categories'&&isset($r['parent_id'])){$parents[$r['id']]=$r['parent_id'];$r['parent_id']=null;}
                insert($table,$r);
            }
            foreach($parents as $id=>$parent)sql('UPDATE categories SET parent_id=? WHERE id=?',[$parent,$id]);
            $actual=(int)one('SELECT COUNT(*) AS n FROM `'.$table.'`')['n'];if($actual!==count($source))throw new RuntimeException('Row count mismatch: '.$table);$report[$table]=$actual;
        }
        $next=max((int)($snapshot['sequence']??13),(int)(one('SELECT MAX(proforma_no) AS n FROM orders')['n']??12)+1);sql('UPDATE invoice_counter SET next_value=? WHERE id=1',[$next]);
        foreach(rows('SELECT DISTINCT base_name FROM product_images') as $im)foreach(['.jpg','-sm.jpg'] as $suffix){if(!preg_match('/^[a-zA-Z0-9_-]+$/',$im['base_name'])||!is_file(ROOT.'/public/uploads/products/'.$im['base_name'].$suffix))throw new RuntimeException('Missing product image: '.$im['base_name'].$suffix);}
    });
    file_put_contents(ROOT.'/storage/migration-report.json',json_encode(['snapshotSha256'=>hash_file('sha256',$path),'importedAt'=>gmdate(DATE_ATOM),'rows'=>$report],JSON_PRETTY_PRINT));echo "Snapshot imported with matching row counts; invoice sequence and image references checked.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
