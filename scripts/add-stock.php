<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
// Explicit CLI operation only; never run automatically during deployment.
$amount=filter_var($argv[1]??null,FILTER_VALIDATE_INT);
if(!$amount||$amount<1||$amount>10000){fwrite(STDERR,"Usage: php scripts/add-stock.php <units, 1-10000>\n");exit(1);}
try{
 $result=tx(function()use($amount){
  $before=rows('SELECT id,stock_quantity,in_stock FROM products ORDER BY id FOR UPDATE');
  foreach($before as $p)if((int)$p['stock_quantity']>2147483647-$amount)throw new RuntimeException('Stock would exceed its storage limit.');
  $backup=ROOT.'/storage/stock-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
  $data=json_encode(['database'=>env('DB_NAME'),'addedPerProduct'=>$amount,'createdAt'=>gmdate('c'),'products'=>$before],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
  if(file_put_contents($backup,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Could not save stock backup; no stock changed.');
  sql('UPDATE products SET stock_quantity=stock_quantity+?,in_stock=1,updated_at=NOW(3)',[$amount]);
  $after=rows('SELECT id,stock_quantity FROM products ORDER BY id');
  if(count($before)!==count($after))throw new RuntimeException('Product count changed.');
  foreach($before as $i=>$p)if($p['id']!==$after[$i]['id']||(int)$after[$i]['stock_quantity']!==(int)$p['stock_quantity']+$amount)throw new RuntimeException('Stock verification failed.');
  return ['products'=>count($before),'addedPerProduct'=>$amount,'totalUnitsAdded'=>count($before)*$amount,'backup'=>$backup];
 });echo json_encode($result,JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
