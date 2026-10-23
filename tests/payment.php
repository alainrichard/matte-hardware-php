<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require ROOT.'/src/api.php';
$count=0;
function expectValue(bool $value,string $label): void {global $count;if(!$value)throw new RuntimeException($label);$count++;}
function expectError(callable $fn,int $status,string $label): void {try{$fn();throw new RuntimeException('Expected rejection: '.$label);}catch(HttpError $e){expectValue($e->status===$status,$label);}}
try{
    // Roll back every fixture; no network or real payment request is made.
    db()->beginTransaction();
    $id=insert('orders',['public_token'=>b64(random_bytes(18)),'status'=>'submitted','customer_name'=>'Payment fixture','customer_phone'=>'0788553011','total_rwf'=>1000,'invoice_requested'=>false]);
    $make=function(string $target='mtnrwanda')use($id){$ref=uuid();$a=['reference'=>$ref,'order_id'=>$id,'external_id'=>'fixture-'.$ref,'amount'=>$target==='sandbox'?1:1000,'currency'=>$target==='sandbox'?'EUR':'RWF','phone'=>'250788553011','target'=>$target,'base_url'=>$target==='sandbox'?'https://sandbox.momodeveloper.mtn.com':'https://proxy.momoapi.mtn.com'];insert('momo_payments',$a);sql("UPDATE orders SET momo_reference_id=?,status='submitted',payment_ref=NULL WHERE id=?",[$ref,$id]);return $a;};
    $a=$make();$r=['amount'=>'1000','currency'=>'RWF','externalId'=>$a['external_id'],'payer'=>['partyIdType'=>'MSISDN','partyId'=>$a['phone']],'financialTransactionId'=>'provider-fixture'];
    expectValue(momoMatches($a,$r),'Exact provider match');
    foreach(['amount'=>'1','currency'=>'EUR','externalId'=>'other'] as $key=>$v)expectValue(!momoMatches($a,[...$r,$key=>$v]),'Reject mismatched '.$key);
    expectValue(!momoMatches($a,[...$r,'payer'=>['partyIdType'=>'MSISDN','partyId'=>'250700000000']]),'Reject mismatched payer');
    // Finish uses nested savepoints in tests and outer transactions in production.
    momoFinish($a,'SUCCESSFUL',$r);expectValue(one('SELECT status FROM orders WHERE id=?',[$id])['status']==='paid','Verified production success pays order');
    momoFinish($a,'FAILED',[],'PAYMENT_FAILED');expectValue(one('SELECT status FROM orders WHERE id=?',[$id])['status']==='paid','Terminal callback cannot reverse payment');
    $a=$make('sandbox');$r=['amount'=>'1','currency'=>'EUR','externalId'=>$a['external_id'],'payer'=>['partyIdType'=>'MSISDN','partyId'=>$a['phone']]];momoFinish($a,'SUCCESSFUL',$r);$o=one('SELECT * FROM orders WHERE id=?',[$id]);expectValue($o['status']==='submitted'&&$o['momo_reference_id']===null,'Sandbox never marks real order paid');
    $a=$make();momoFinish($a,'SUCCESSFUL',[...$r,'currency'=>'RWF','amount'=>'999']);expectValue(one('SELECT status FROM momo_payments WHERE reference=?',[$a['reference']])['status']==='REVIEW','Mismatched success held for review');expectValue(one('SELECT momo_reference_id FROM orders WHERE id=?',[$id])['momo_reference_id']===$a['reference'],'Review does not unlock duplicate charge');
    expectError(fn()=>changeOrder($id,['status'=>'cancelled']),409,'Admin cannot bypass pending reconciliation');
    sql("UPDATE momo_payments SET status='FAILED' WHERE reference=?",[$a['reference']]);$a=$make();momoFinish($a,'FAILED',[],'PAYER_REJECTED');expectValue(one('SELECT momo_reference_id FROM orders WHERE id=?',[$id])['momo_reference_id']===null,'Definitive failure unlocks retry');
    expectValue(failureCode('unknown provider message')==='PAYMENT_FAILED','Unknown provider details hidden');expectValue(failureCode(['code'=>'not-enough-funds'])==='NOT_ENOUGH_FUNDS','Known payment errors normalized');
    expectValue(waAllowed(gmdate('Y-m-d H:i:s',time()-3600)),'WhatsApp recent reply window');expectValue(!waAllowed(gmdate('Y-m-d H:i:s',time()-86401)),'WhatsApp expired reply window');expectValue(!waAllowed(gmdate('Y-m-d H:i:s',time()+60)),'WhatsApp future timestamp rejected');
    foreach(['WHATSAPP_ENABLED=true','WHATSAPP_ACCESS_TOKEN=test','WHATSAPP_PHONE_NUMBER_ID=123456789','WHATSAPP_APP_SECRET=test-signing-secret','WHATSAPP_VERIFY_TOKEN=test-verify'] as $setting){[$key,$value]=explode('=',$setting,2);$GLOBALS['matteConfig'][$key]=$value;}
    $payload=['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>'123456789'],'contacts'=>[['wa_id'=>'250788000001','profile'=>['name'=>'Fixture']]],'messages'=>[['id'=>'fixture-'.uuid(),'from'=>'250788000001','timestamp'=>(string)time(),'type'=>'text','text'=>['body'=>'Fixture incoming message']]]]]]]]];
    $raw=json_encode($payload);$_SERVER['HTTP_X_HUB_SIGNATURE_256']='sha256='.str_repeat('0',64);expectError(fn()=>waWebhook($raw),403,'Reject unsigned WhatsApp body');$_SERVER['HTTP_X_HUB_SIGNATURE_256']='sha256='.hash_hmac('sha256',$raw,'test-signing-secret');waWebhook($raw);waWebhook($raw);$c=one('SELECT * FROM whatsapp_conversations WHERE phone_number_id=? AND customer_phone=?',['123456789','250788000001']);expectValue((int)$c['unread']===1,'Duplicate webhook does not double unread count');expectValue((int)one('SELECT COUNT(*) AS n FROM whatsapp_messages WHERE conversation_id=?',[$c['id']])['n']===1,'Duplicate webhook does not duplicate message');
    $request=uuid();$mid=insert('whatsapp_messages',['conversation_id'=>$c['id'],'request_id'=>$request,'request_hash'=>hash('sha256','Reply'),'direction'=>'outbound','body'=>'Reply','status'=>'read','provider_id'=>'fixture-outbound']);
    $value=['metadata'=>['phone_number_id'=>'123456789'],'statuses'=>[['id'=>'fixture-outbound','status'=>'sent','recipient_id'=>'250788000001','biz_opaque_callback_data'=>$request]]];$raw=json_encode(['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['field'=>'messages','value'=>$value]]]]]);$_SERVER['HTTP_X_HUB_SIGNATURE_256']='sha256='.hash_hmac('sha256',$raw,'test-signing-secret');waWebhook($raw);expectValue(one('SELECT status FROM whatsapp_messages WHERE id=?',[$mid])['status']==='read','Out-of-order webhook does not downgrade read receipt');
    $prior=waSend((int)$c['id'],['body'=>'Reply','requestId'=>$request]);expectValue((int)$prior['id']===$mid,'Repeated WhatsApp request returns durable result without sending');expectError(fn()=>waSend((int)$c['id'],['body'=>'Different reply','requestId'=>$request]),409,'Reject reused request id with changed body');
    db()->rollBack();echo "PASS: $count payment and webhook checks. No external requests sent.\n";
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();fwrite(STDERR,(string)$e."\n");exit(1);}
