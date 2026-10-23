<?php
// Fixed provider URL: no user-controlled hosts, redirects or TLS exceptions.
function parseExchangeRate(string $raw): array {
    $rows=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||count($rows)!==1)throw new RuntimeException('Unexpected exchange response.');
    $r=$rows[0]??[];$date=$r['date']??'';$rate=$r['rate']??null;
    if(($r['base']??'')!=='USD'||($r['quote']??'')!=='RWF'||!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!is_numeric($rate)||!is_finite((float)$rate)||(float)$rate<1||(float)$rate>1000000)throw new RuntimeException('Invalid exchange rate.');
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$day||$day->format('Y-m-d')!==$date||$date>gmdate('Y-m-d',time()+86400)||$date<gmdate('Y-m-d',time()-14*86400))throw new RuntimeException('Exchange rate date is outside the accepted window.');
    return ['usdRwfRate'=>round((float)$rate,4),'date'=>$date,'source'=>'Frankfurter'];
}
function fetchExchangeRate(): array {
    $ch=curl_init('https://api.frankfurter.dev/v2/rates?base=USD&quotes=RWF');$body='';
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'MATTE/1.0',CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>16384)return 0;$body.=$chunk;return strlen($chunk);}]);
    try{if(curl_exec($ch)===false||curl_getinfo($ch,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('Exchange provider unavailable.');return parseExchangeRate($body);}finally{curl_close($ch);}
}
function exchangeRate(): array {
    $manual=(float)siteContent()['business']['usdRwfRate'];
    $fallback=['usdRwfRate'=>$manual,'rwfUsdRate'=>1/$manual,'source'=>'configured fallback','date'=>null,'stale'=>true];
    if(env('FRANKFURTER_ENABLED','true')!=='true')return $fallback;
    $path=ROOT.'/storage/exchange-'.hash('sha256',env('DB_NAME')).'.json';
    $lock=@fopen($path.'.lock','c');
    $read=fn()=>is_file($path)?(json_decode((string)file_get_contents($path),true)?:[]):[];
    $cache=$read();
    try{
        if($lock&&flock($lock,LOCK_EX|LOCK_NB)){
            $cache=$read();
            if(time()-(int)($cache['checkedAt']??0)>=3600){
                try{$cache=fetchExchangeRate()+['fetchedAt'=>time()];}catch(Throwable $e){/* Keep the last validated value during provider outages. */}
                $cache['checkedAt']=time();$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
                if(file_put_contents($tmp,json_encode($cache),LOCK_EX)!==false){if(!@rename($tmp,$path))@unlink($tmp);}
            }
        }
    }finally{if($lock)fclose($lock);}
    $rate=(float)($cache['usdRwfRate']??0);
    if($rate<=0||time()-(int)($cache['fetchedAt']??0)>7*86400)return $fallback;
    return ['usdRwfRate'=>$rate,'rwfUsdRate'=>1/$rate,'source'=>'Frankfurter','date'=>$cache['date'],'stale'=>time()-(int)$cache['fetchedAt']>86400];
}
