<?php
require dirname(__DIR__).'/src/bootstrap.php';require ROOT.'/src/api.php';
if(!str_ends_with(env('DB_NAME'),'_test'))throw new RuntimeException('Use the test database.');
$count=0;
function fxAssert(bool $ok): void {global $count;if(!$ok)throw new RuntimeException('Exchange assertion failed.');$count++;}
$valid=['date'=>gmdate('Y-m-d'),'base'=>'USD','quote'=>'RWF','rate'=>1475.62];
$parsed=parseExchangeRate(json_encode([$valid]));fxAssert($parsed['usdRwfRate']===1475.62);fxAssert(round(147562/$parsed['usdRwfRate'],2)===100.0);
foreach([['rate'=>0],['rate'=>-1],['rate'=>'bad'],['quote'=>'EUR'],['base'=>'RWF'],['date'=>'2026-02-30'],['date'=>'2000-01-01']] as $bad){$rejected=false;try{parseExchangeRate(json_encode([array_replace($valid,$bad)]));}catch(Throwable $e){$rejected=true;}fxAssert($rejected);}
$path=ROOT.'/storage/exchange-'.hash('sha256',env('DB_NAME')).'.json';$old=is_file($path)?file_get_contents($path):null;
try{
 $GLOBALS['matteConfig']['FRANKFURTER_ENABLED']='true';
 file_put_contents($path,json_encode($parsed+['checkedAt'=>time(),'fetchedAt'=>time()]));
 $fx=exchangeRate();fxAssert($fx['source']==='Frankfurter'&&!$fx['stale']);fxAssert(abs($fx['rwfUsdRate']*1475.62-1)<0.0000001);
 db()->beginTransaction();
 try{
  $category=insert('categories',['name'=>'FX fixture','slug'=>'fx-'.uuid()]);
  $product=insert('products',['name'=>'FX fixture','slug'=>'fx-'.uuid(),'category_id'=>$category,'price_rwf'=>147562,'price_confirmed'=>1,'stock_quantity'=>2]);
  $created=createOrder(['customer'=>['name'=>'FX test','phone'=>'0788000000'],'currency'=>'USD','invoiceRequested'=>false,'items'=>[['productId'=>$product,'qty'=>1]]]);
  $order=orderByToken($created['token']);fxAssert((float)$order['usd_rwf_rate']===1475.62);fxAssert(mapOrder($order)['totalAmount']===100.0);
  file_put_contents($path,json_encode(array_replace($parsed,['usdRwfRate'=>1500])+['checkedAt'=>time(),'fetchedAt'=>time()]));
  fxAssert(exchangeRate()['usdRwfRate']===1500.0);fxAssert(mapOrder(orderByToken($created['token']))['totalAmount']===100.0);
 }finally{db()->rollBack();}
 file_put_contents($path,json_encode($parsed+['checkedAt'=>time(),'fetchedAt'=>time()-2*86400]));fxAssert(exchangeRate()['stale']);
 file_put_contents($path,json_encode($parsed+['checkedAt'=>time(),'fetchedAt'=>time()-8*86400]));fxAssert(exchangeRate()['source']==='configured fallback');
 $GLOBALS['matteConfig']['FRANKFURTER_ENABLED']='false';fxAssert(exchangeRate()['usdRwfRate']===(float)siteContent()['business']['usdRwfRate']);
 echo "PASS: $count exchange-rate validation, conversion, caching and fallback checks.\n";
}finally{if($old!==null)file_put_contents($path,$old);else @unlink($path);}
