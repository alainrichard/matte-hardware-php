<?php
function httpCall(string $url,string $method='GET',array $headers=[],mixed $body=null,int $limit=16777216): array {
    $ch=curl_init($url);$data='';curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$data,$limit){if(strlen($data)+strlen($chunk)>$limit)return 0;$data.=$chunk;return strlen($chunk);}]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($ok===false)throw new RuntimeException('Provider request unavailable.');return ['status'=>$status,'body'=>$data,'json'=>json_decode($data,true)];
}
function momoErrors(): array {
    $e=[];foreach(['MOMO_API_USER','MOMO_API_KEY','MOMO_SUBSCRIPTION_KEY'] as $k)if(!env($k))$e[]=$k;
    $url=rtrim(env('MOMO_BASE_URL','https://sandbox.momodeveloper.mtn.com'),'/');$target=env('MOMO_TARGET_ENVIRONMENT','sandbox');$u=parse_url($url);
    if(!$u||($u['scheme']??'')!=='https'||!in_array($u['host']??'',['sandbox.momodeveloper.mtn.com','proxy.momoapi.mtn.com','momoapi.momo.africa'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port'])||!in_array($u['path']??'',['','/'],true)||isset($u['query'])||isset($u['fragment']))$e[]='MOMO_BASE_URL';
    if(($target==='sandbox')!==(($u['host']??'')==='sandbox.momodeveloper.mtn.com')||!preg_match('/^[a-z][a-z0-9_-]{1,40}$/',$target))$e[]='MOMO_TARGET_ENVIRONMENT';
    $cb=env('MOMO_CALLBACK_URL');if($cb&&(parse_url($cb,PHP_URL_SCHEME)!=='https'||parse_url($cb,PHP_URL_USER)||parse_url($cb,PHP_URL_PASS)||parse_url($cb,PHP_URL_QUERY)||parse_url($cb,PHP_URL_FRAGMENT)))$e[]='MOMO_CALLBACK_URL';return $e;
}
function momoReady(): bool {return env('MOMO_ENABLED')==='true'&&!momoErrors();}
function momoRequest(string $path,string $method='GET',?array $body=null,array $extra=[]): array {
    $base=rtrim(env('MOMO_BASE_URL','https://sandbox.momodeveloper.mtn.com'),'/');$target=env('MOMO_TARGET_ENVIRONMENT','sandbox');$headers=['Ocp-Apim-Subscription-Key: '.env('MOMO_SUBSCRIPTION_KEY'),'X-Target-Environment: '.$target];
    $r=httpCall($base.'/collection/token'.($target==='sandbox'?'/':''),'POST',[...$headers,'Authorization: Basic '.base64_encode(env('MOMO_API_USER').':'.env('MOMO_API_KEY'))],'');
    if($r['status']<200||$r['status']>=300||!is_string($r['json']['access_token']??null))throw new RuntimeException('MTN authentication unavailable.');
    return httpCall($base.$path,$method,[...$headers,'Authorization: Bearer '.$r['json']['access_token'],'Content-Type: application/json',...$extra],$body===null?null:json_encode($body));
}
function momoMessages(): array {return [
 'NOT_ENOUGH_FUNDS'=>'Your MoMo balance is too low. Add enough for the payment and any MTN fees, then try again.',
 'PAYER_NOT_FOUND'=>'We could not find a MoMo wallet for this number. Check the number or use another MoMo number.',
 'SENDER_ACCOUNT_NOT_ACTIVE'=>'This MoMo wallet is not active. Use another number or contact MTN.',
 'PAYER_LIMIT_REACHED'=>'This wallet has reached its payment limit. Use another number or contact MTN.',
 'PAYEE_NOT_ALLOWED_TO_RECEIVE'=>'MATTE cannot receive this MoMo payment right now. Choose another method or contact us.',
 'PAYEE_NOT_FOUND'=>'MATTE cannot receive this MoMo payment right now. Choose another method or contact us.',
 'PAYEE_LIMIT_REACHED'=>'MATTE cannot receive this MoMo payment right now. Choose another method or contact us.',
 'COULD_NOT_PERFORM_TRANSACTION'=>'The payment was not completed. Try again and approve the request on your phone.',
 'PAYER_REJECTED'=>'The payment was declined on your phone. You can try again when ready.',
 'EXPIRED'=>'The payment request expired. Try again and approve the new request on your phone.',
 'VALIDATION_ERROR'=>'Check your MoMo number. If it is correct, contact MATTE.',
 'REQUEST_REJECTED'=>'MoMo is unavailable for this payment. Choose another method or contact MATTE.',
 'TRANSFER_TYPE_UNKNOWN'=>'MoMo is unavailable for this payment. Choose another method or contact MATTE.',
 'PAYMENT_FAILED'=>'The payment did not complete. You can try again or choose another method.',
 'STATUS_UNAVAILABLE'=>'MTN is taking longer to confirm your payment. We are checking automatically. Please do not pay again.',
 'PAYMENT_MISMATCH'=>'We need to check this payment with MTN. Please contact MATTE before paying again.'
 ];}
function failureCode(mixed $v,string $fallback='PAYMENT_FAILED'): string {$s=is_array($v)?($v['code']??''):$v;$s=is_string($s)?preg_replace('/[\s-]+/','_',strtoupper(trim($s))):'';return isset(momoMessages()[$s])?$s:$fallback;}
function momoPublic(?array $a): ?array {return $a?['reference'=>$a['reference'],'status'=>$a['status'],'amount'=>(float)$a['amount'],'currency'=>$a['currency'],'sandbox'=>$a['target']==='sandbox','failureCode'=>$a['failure_code'],'message'=>$a['failure_code']?(momoMessages()[$a['failure_code']]??momoMessages()['PAYMENT_FAILED']):null]:null;}
function momoLatest(string $token): ?array {return one('SELECT p.* FROM momo_payments p JOIN orders o ON o.id=p.order_id WHERE o.public_token=? ORDER BY p.created_at DESC,p.reference DESC LIMIT 1',[$token]);}
function momoStart(string $token,mixed $raw): ?array {
    rate('momo',30,60);if(!momoReady())fail('MTN MoMo is not configured yet. Please contact MATTE.',503);$sandbox=env('MOMO_TARGET_ENVIRONMENT','sandbox')==='sandbox';$phone=is_string($raw)?preg_replace('/[\s()+-]/','',$raw):'';if(preg_match('/^07\d{8}$/',$phone))$phone='250'.substr($phone,1);if(!preg_match($sandbox?'/^\d{10,15}$/':'/^2507\d{8}$/',$phone))fail('Enter a valid Rwanda mobile money number.');
    $a=tx(function()use($token,$phone,$sandbox){$o=orderByToken($token,true);if(!in_array($o['status'],UNPAID,true))fail('This order is not awaiting payment.',409);if($o['momo_reference_id']){$p=one('SELECT * FROM momo_payments WHERE reference=?',[$o['momo_reference_id']]);if(!$p)fail('Payment needs staff review.',409);return $p;}if($o['payment_ref'])fail('Payment is already awaiting verification. Contact MATTE before paying again.',409);if($o['total_rwf']<=0)fail('Invalid order amount.');$ref=uuid();$a=['reference'=>$ref,'order_id'=>$o['id'],'external_id'=>'matte-'.$o['id'].'-'.$ref,'amount'=>$sandbox?1:(int)$o['total_rwf'],'currency'=>$sandbox?'EUR':'RWF','phone'=>$phone,'target'=>env('MOMO_TARGET_ENVIRONMENT','sandbox'),'base_url'=>rtrim(env('MOMO_BASE_URL','https://sandbox.momodeveloper.mtn.com'),'/')];insert('momo_payments',$a);sql("UPDATE orders SET momo_reference_id=?,payment_method='mtn',updated_at=NOW(3) WHERE id=?",[$ref,$o['id']]);return $a;});momoReconcile($a['reference']);return momoPublic(momoLatest($token));
}
function momoMatches(array $a,array $r): bool {return preg_match('/^\d+(?:\.\d+)?$/',(string)($r['amount']??''))&&(float)$r['amount']===(float)$a['amount']&&($r['currency']??null)===$a['currency']&&($r['externalId']??null)===$a['external_id']&&($r['payer']['partyIdType']??null)==='MSISDN'&&($r['payer']['partyId']??null)===$a['phone'];}
function momoFinish(array $a,string $status,array $result=[],?string $failure=null): void {tx(function()use($a,$status,$result,$failure){$o=one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$a['order_id']]);$current=one('SELECT * FROM momo_payments WHERE reference=? FOR UPDATE',[$a['reference']]);if(!$current||!in_array($current['status'],['CREATED','PENDING'],true))return;
    if($status==='SUCCESSFUL'&&(!momoMatches($a,$result)||$o['momo_reference_id']!==$a['reference']||!in_array($o['status'],UNPAID,true)||($a['target']!=='sandbox'&&(float)$a['amount']!==(float)$o['total_rwf']))){$status='REVIEW';$failure='PAYMENT_MISMATCH';}
    update('momo_payments',['status'=>$status,'provider_reference'=>is_string($result['financialTransactionId']??null)?substr($result['financialTransactionId'],0,100):null,'failure_code'=>$failure,'updated_at'=>gmdate('Y-m-d H:i:s')],'reference=?',[$a['reference']]);
    if($status==='SUCCESSFUL'&&$a['target']!=='sandbox')sql("UPDATE orders SET status='paid',payment_method='mtn',payment_ref=?,updated_at=NOW(3) WHERE id=?",[$a['reference'],$a['order_id']]);
    elseif($status==='FAILED'||$status==='SUCCESSFUL'&&$a['target']==='sandbox')sql('UPDATE orders SET momo_reference_id=NULL,updated_at=NOW(3) WHERE id=? AND momo_reference_id=?',[$a['order_id'],$a['reference']]);
});}
function momoReconcile(string $ref): void {
    if(!momoReady())return;
    $a=tx(function()use($ref){$a=one("SELECT * FROM momo_payments WHERE reference=? AND status IN ('CREATED','PENDING') AND next_check_at<=NOW(3) AND target=? AND base_url=? FOR UPDATE",[$ref,env('MOMO_TARGET_ENVIRONMENT','sandbox'),rtrim(env('MOMO_BASE_URL','https://sandbox.momodeveloper.mtn.com'),'/')]);if($a)sql('UPDATE momo_payments SET next_check_at=DATE_ADD(NOW(3),INTERVAL 45 SECOND) WHERE reference=?',[$ref]);return $a;});if(!$a)return;
    try{$r=momoRequest('/collection/v1_0/requesttopay/'.$ref);if($r['status']!==404&&($r['status']<200||$r['status']>=300))throw new RuntimeException('Status unavailable.');$result=$r['status']===404?null:$r['json'];
        if(($result['status']??'')==='SUCCESSFUL'){momoFinish($a,'SUCCESSFUL',$result);return;}if(($result['status']??'')==='FAILED'){momoFinish($a,'FAILED',$result,failureCode($result['reason']??null));return;}
        if(!$result&&$a['status']==='CREATED'){$extra=['X-Reference-Id: '.$ref];if(env('MOMO_CALLBACK_URL'))$extra[]='X-Callback-Url: '.rtrim(env('MOMO_CALLBACK_URL'),'/').'/'.$ref;
            $r=momoRequest('/collection/v1_0/requesttopay','POST',['amount'=>(string)$a['amount'],'currency'=>$a['currency'],'externalId'=>$a['external_id'],'payer'=>['partyIdType'=>'MSISDN','partyId'=>$a['phone']],'payerMessage'=>'MATTE order payment','payeeNote'=>'MATTE order payment'],$extra);
            if(in_array($r['status'],[400,401,403,422],true)){momoFinish($a,$a['failure_code']==='STATUS_UNAVAILABLE'?'REVIEW':'FAILED',[],failureCode($r['json']['code']??$r['json']['reason']??null,'REQUEST_REJECTED'));return;}
            if(!in_array($r['status'],[202,409],true))throw new RuntimeException('Uncertain payment request.');
        }
        if($result||$a['status']==='CREATED')sql("UPDATE momo_payments SET status='PENDING',failure_code=NULL,updated_at=NOW(3) WHERE reference=? AND status IN ('CREATED','PENDING')",[$ref]);
    }catch(Throwable $e){sql("UPDATE momo_payments SET failure_code='STATUS_UNAVAILABLE',updated_at=NOW(3) WHERE reference=? AND status IN ('CREATED','PENDING')",[$ref]);}
}
function momoPoll(): void {if(!momoReady())return;foreach(rows("SELECT reference FROM momo_payments WHERE status IN ('CREATED','PENDING') AND next_check_at<=NOW(3) ORDER BY next_check_at LIMIT 20") as $a)momoReconcile($a['reference']);}
