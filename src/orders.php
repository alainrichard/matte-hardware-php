<?php
const UNPAID=['submitted','proforma_issued'];
const METHODS=['mtn','airtel','card','bank'];
const STATUSES=['submitted','proforma_issued','paid','preparing','delivered','cancelled'];
function orderByToken(string $token,bool $lock=false): array {
    if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$token))fail('Order not found.',404);
    $o=one('SELECT * FROM orders WHERE public_token=?'.($lock?' FOR UPDATE':''),[$token]);if(!$o)fail('Order not found.',404);return $o;
}
function orderItems(int $id): array {return rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$id]);}
function mapOrder(array $o,bool $public=false): array {
    $items=orderItems((int)$o['id']);$r=['id'=>(int)$o['id'],'token'=>$o['public_token'],'proformaNo'=>$o['proforma_no']===null?null:(int)$o['proforma_no'],'status'=>$o['status'],'createdAt'=>$o['created_at'].'Z','customer'=>['name'=>$o['customer_name'],'phone'=>$o['customer_phone'],'email'=>$o['customer_email'],'company'=>$o['customer_company'],'tin'=>$o['customer_tin'],'purchaseCode'=>$o['purchase_code'],'whatsapp'=>waPhone($o['customer_phone'])],'delivery'=>['method'=>$o['delivery_method'],'address'=>$o['delivery_address'],'latitude'=>$o['latitude'],'longitude'=>$o['longitude']],'note'=>$o['note'],'total'=>(int)$o['total_rwf'],'currency'=>$o['currency'],'exchangeRate'=>(float)$o['usd_rwf_rate'],'totalAmount'=>$o['currency']==='USD'?round($o['total_rwf']/$o['usd_rwf_rate'],2):(int)$o['total_rwf'],'invoiceRequested'=>(bool)$o['invoice_requested'],'notifyOnDelivery'=>(bool)$o['notify_on_delivery'],'deliveryNotifiedAt'=>$o['delivery_notified_at'],'deliveryEmailStatus'=>!$o['notify_on_delivery']?'not_requested':($o['delivery_notified_at']?'sent':(env('SMTP_HOST')?'pending':'smtp_not_configured')),'serviceRequest'=>$o['service_request'],'serviceNote'=>$o['service_note'],'itemCount'=>count($items),'payment'=>['method'=>$o['payment_method'],'reference'=>$o['payment_ref'],'momoReference'=>$o['momo_reference_id']],'items'=>array_map(fn($i)=>['description'=>$i['description'],'unitPrice'=>(int)$i['unit_price_rwf'],'qty'=>(int)$i['qty'],'lineTotal'=>(int)$i['line_total_rwf'],'productId'=>$i['product_id']===null?null:(int)$i['product_id']],$items)];
    if($public){$site=siteContent();$r['company']=$site['business'];$r['branding']=$site['branding'];$r['paymentInstructions']=$site['business']['paymentInstructions'];$r['paymentOptions']=array_intersect_key($site['business'],array_flip(['mtnPayNumber','airtelPayNumber','cardPaymentUrl','mtnUssdCode','airtelUssdCode','airtelPaymentUrl','bankName','bankAccountName','bankAccountNumber','bankSwift','bankInstructions']));$sandbox=env('MOMO_TARGET_ENVIRONMENT','sandbox')==='sandbox';$r['payment']['verificationStatus']=in_array($o['status'],['paid','preparing','delivered'],true)?'verified':($o['status']==='cancelled'?'cancelled':($o['payment_ref']?'under_review':($o['momo_reference_id']?'processing':'unpaid')));$r['payment']['attempt']=momoPublic(momoLatest($o['public_token']));$r['momo']=['enabled'=>momoReady(),'sandbox'=>$sandbox,'amount'=>$sandbox?1:(int)$o['total_rwf'],'currency'=>$sandbox?'EUR':'RWF'];unset($r['id']);}return $r;
}
function waPhone(string $s): string {$s=preg_replace('/\D/','',$s);return str_starts_with($s,'0')?'250'.substr($s,1):$s;}
function createOrder(array $b): array {
    rate('orders',20);$user=auth('customer',true);$c=is_array($b['customer']??null)?$b['customer']:[];$name=clean($c['name']??'',120);$phone=clean($c['phone']??'',30);$email=clean($c['email']??'',160);
    if(!$name||strlen(preg_replace('/\D/','',$phone))<9)fail('Enter your name and a valid phone number.');
    if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Enter a valid email address.');
    $invoice=($b['invoiceRequested']??true)!==false;$isTin=($c['invoiceType']??'')==='tin';$tin=clean($c['tin']??'',9);$company=clean($c['company']??'',120);$purchase=clean($c['purchaseCode']??'',80);
    if($invoice&&$isTin&&(!preg_match('/^\d{9}$/',$tin)||!$company||!$purchase))fail('A TIN invoice requires a 9-digit TIN, company name and RRA purchase code.');
    $notify=($b['notifyOnDelivery']??false)===true;if($notify&&!$email)fail('Enter an email for delivery notifications.');
    $delivery=is_array($b['delivery']??null)?$b['delivery']:[];$method=($delivery['method']??'')==='delivery'?'delivery':'pickup';$address=clean($delivery['address']??'',300);$pin=pin($method==='delivery'?$delivery:[]);if($method==='delivery'&&!$address)fail('Enter a delivery address.');
    $pay=$b['paymentMethod']??null;if($pay!==null&&!in_array($pay,METHODS,true))fail('Choose a valid payment method.');
    $wanted=[];if(!is_array($b['items']??null)||count($b['items'])<1||count($b['items'])>80)fail('A cart must contain between 1 and 80 items.');
    foreach($b['items'] as $it){$id=num($it['productId']??0);$qty=num($it['qty']??0);if($id<1||$qty<1||$qty>999)fail('Some cart items are invalid.');$wanted[$id]=($wanted[$id]??0)+$qty;if($wanted[$id]>999)fail('Maximum quantity per product is 999.');}ksort($wanted);
    $requestId=$b['requestId']??null;if($requestId!==null&&!isUuid($requestId))fail('Invalid checkout request. Refresh and try again.');
    $payload=$b;unset($payload['requestId']);$payloadHash=hash('sha256',json_encode([$user['sub']??null,$payload],JSON_THROW_ON_ERROR));
    $rate=exchangeRate()['usdRwfRate'];$token=b64(random_bytes(18));
    $o=tx(function()use($b,$user,$name,$phone,$email,$invoice,$isTin,$tin,$company,$purchase,$notify,$method,$address,$pin,$pay,$wanted,$rate,$token,$requestId,$payloadHash){
        if($requestId){sql('INSERT INTO checkout_requests(request_id,payload_hash) VALUES(?,?) ON DUPLICATE KEY UPDATE request_id=request_id',[$requestId,$payloadHash]);$request=one('SELECT * FROM checkout_requests WHERE request_id=? FOR UPDATE',[$requestId]);if(!hash_equals($request['payload_hash'],$payloadHash))fail('This checkout request was already used with different details. Refresh your checkout.',409);if($request['order_id']){$prior=one('SELECT * FROM orders WHERE id=?',[$request['order_id']]);return ['token'=>$prior['public_token'],'proformaNo'=>$prior['proforma_no'],'status'=>$prior['status'],'invoiceRequested'=>(bool)$prior['invoice_requested']];}}
        $visible=visibleIds(rows('SELECT * FROM categories'));
        $lines=[];$total=0;foreach($wanted as $id=>$qty){$p=one('SELECT * FROM products WHERE id=? FOR UPDATE',[$id]);
            if(!$p||!$p['is_active']||!in_array((int)$p['category_id'],$visible,true)||$p['stock_quantity']<$qty||$p['price_rwf']<=0||(env('REQUIRE_CONFIRMED_PRICES','true')==='true'&&!$p['price_confirmed']))fail(($p['name']??'An item').' is not available in the requested quantity. Contact MATTE.',422,['productId'=>$id]);
            $line=['product_id'=>$id,'description'=>$p['name'].($p['color_name']?' - '.$p['color_name']:''),'unit_price_rwf'=>(int)$p['price_rwf'],'qty'=>$qty,'line_total_rwf'=>$qty*(int)$p['price_rwf']];$total+=$line['line_total_rwf'];$lines[]=$line;
        }if($total>2000000000)fail('Order total is too large. Please contact MATTE.');
        $no=null;if($invoice){$no=(int)one('SELECT next_value FROM invoice_counter WHERE id=1 FOR UPDATE')['next_value'];sql('UPDATE invoice_counter SET next_value=next_value+1 WHERE id=1');}
        $values=['public_token'=>$token,'proforma_no'=>$no,'status'=>$invoice?'proforma_issued':'submitted','customer_name'=>$name,'customer_phone'=>$phone,'customer_email'=>$email?:null,'customer_company'=>$invoice?($company?:null):null,'customer_tin'=>$invoice&&$isTin?$tin:null,'purchase_code'=>$invoice&&$isTin?$purchase:null,'delivery_method'=>$method,'delivery_address'=>$method==='delivery'?$address:null,'note'=>textValue($b['note']??'',600)?:null,'total_rwf'=>$total,'currency'=>($b['currency']??'')==='USD'?'USD':'RWF','usd_rwf_rate'=>$rate>0?$rate:1400,'invoice_requested'=>$invoice,'notify_on_delivery'=>$notify,'service_request'=>in_array($b['serviceRequest']??'', ['technician','plumber'],true)?$b['serviceRequest']:'none','service_note'=>textValue($b['serviceNote']??'',600)?:null,'customer_id'=>$user['sub']??null,'payment_method'=>$pay,...$pin];$id=insert('orders',$values);
        foreach($lines as $l){sql('UPDATE products SET in_stock=(stock_quantity-?>0),stock_quantity=stock_quantity-?,updated_at=NOW(3) WHERE id=?',[$l['qty'],$l['qty'],$l['product_id']]);insert('order_items',['order_id'=>$id,...$l]);}
        if($requestId)sql('UPDATE checkout_requests SET order_id=? WHERE request_id=?',[$id,$requestId]);
        if($invoice&&$email)sql("INSERT IGNORE INTO mail_jobs(order_id,kind) VALUES(?,'proforma')",[$id]);return ['token'=>$token,'proformaNo'=>$no,'status'=>$values['status'],'invoiceRequested'=>$invoice];
    });return $o;
}
function restoreStock(int $id): void {foreach(rows('SELECT product_id,SUM(qty) AS qty FROM order_items WHERE order_id=? AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id',[$id]) as $i)sql('UPDATE products SET stock_quantity=stock_quantity+?,in_stock=1,updated_at=NOW(3) WHERE id=?',[(int)$i['qty'],$i['product_id']]);}
function cancelOrder(string $token): array {tx(function()use($token){$o=orderByToken($token,true);if($o['status']==='cancelled')return;if(!in_array($o['status'],UNPAID,true)||$o['payment_ref']||$o['momo_reference_id'])fail('This order can no longer be cancelled online. Please contact MATTE.',409);restoreStock((int)$o['id']);sql("UPDATE orders SET status='cancelled',updated_at=NOW(3) WHERE id=?",[$o['id']]);});return ['ok'=>true];}
function paymentDetails(string $token,array $b,bool $reference): array {
    rate('payment-details');return tx(function()use($token,$b,$reference){
        $o=orderByToken($token,true);$method=$b['method']??$o['payment_method'];
        if(!in_array($method,METHODS,true))fail('Choose a valid payment method.');
        $ref=null;if($reference){$raw=$b['reference']??null;if(!is_string($raw)||!trim($raw)||mb_strlen(trim($raw))>100)fail('Payment reference must contain 1 to 100 characters.');$ref=clean($raw,100);}
        if($reference&&$o['payment_ref']===$ref&&$o['payment_method']===$method)return ['ok'=>true,'method'=>$method,'reference'=>$ref];
        if(!in_array($o['status'],UNPAID,true)||$o['momo_reference_id']||$o['payment_ref'])fail('Payment details are locked for verification. Contact MATTE to correct them; do not pay again.',409);
        $values=['payment_method'=>$method,'updated_at'=>gmdate('Y-m-d H:i:s')];if($reference)$values['payment_ref']=$ref;
        update('orders',$values,'id=?',[$o['id']]);return ['ok'=>true,'method'=>$method,'reference'=>$ref];
    });
}
function changeOrder(int $id,array $b): array {
    if(!array_intersect(array_keys($b),['status','paymentMethod','paymentRef']))fail('Nothing to update.');
    tx(function()use($id,$b){$o=one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$o)fail('Order not found.',404);if($o['momo_reference_id']&&in_array($o['status'],UNPAID,true))fail('MTN payment needs reconciliation before changing this order.',409);
        $trans=['submitted'=>['paid','cancelled'],'proforma_issued'=>['paid','cancelled'],'paid'=>['preparing'],'preparing'=>['delivered'],'delivered'=>[],'cancelled'=>[]];$status=$b['status']??$o['status'];if(!isset($trans[$status]))fail('Unknown status.');if($status!==$o['status']&&!in_array($status,$trans[$o['status']],true))fail('This status transition is not allowed.',409);
        $method=array_key_exists('paymentMethod',$b)?clean($b['paymentMethod'],60):$o['payment_method'];$ref=array_key_exists('paymentRef',$b)?clean($b['paymentRef'],100):$o['payment_ref'];if($method&&!in_array($method,METHODS,true)&&$method!==$o['payment_method'])fail('Choose a valid payment method.');
        if(!in_array($o['status'],UNPAID,true)&&($method!==$o['payment_method']||$ref!==$o['payment_ref']))fail('Payment details are locked after payment or cancellation.',409);if($status==='paid'&&(!$method||!$ref))fail('Record the verified payment method and transaction reference before marking paid.');
        if($status==='cancelled'&&$o['status']!=='cancelled')restoreStock($id);
        update('orders',['status'=>$status,'payment_method'=>$method?:null,'payment_ref'=>$ref?:null,'updated_at'=>gmdate('Y-m-d H:i:s')],'id=?',[$id]);
        if($status==='delivered'&&$o['notify_on_delivery']&&$o['customer_email']&&!$o['delivery_notified_at'])sql("INSERT IGNORE INTO mail_jobs(order_id,kind) VALUES(?,'delivery')",[$id]);
    });return mapOrder(one('SELECT * FROM orders WHERE id=?',[$id]));
}
function listOrders(array $q,?int $customer=null): mixed {
    $items=[];foreach(rows('SELECT * FROM orders'.($customer?' WHERE customer_id=?':'').' ORDER BY created_at DESC,id DESC',$customer?[$customer]:[]) as $o){
        $map=['status'=>'status','delivery'=>'delivery_method','paymentMethod'=>'payment_method','currency'=>'currency','service'=>'service_request'];$skip=false;foreach($map as $k=>$field)if(!empty($q[$k])&&$o[$field]!==$q[$k])$skip=true;if($skip)continue;
        if(($q['document']??'')==='invoice'&&!$o['invoice_requested']||($q['document']??'')==='direct'&&$o['invoice_requested'])continue;
        $ps=$q['paymentStatus']??'';if($ps==='paid'&&!in_array($o['status'],['paid','preparing','delivered'],true))continue;if(in_array($ps,['awaiting_reference','reference_received'],true)&&(!in_array($o['status'],UNPAID,true)||($ps==='reference_received')!==(bool)$o['payment_ref']))continue;
        if(!empty($q['from'])&&substr($o['created_at'],0,10)<$q['from']||!empty($q['to'])&&substr($o['created_at'],0,10)>$q['to'])continue;
        $r=mapOrder($o);if(!contains(implode(' ',[$o['customer_name'],$o['customer_phone'],$o['customer_email']??'',$o['payment_ref']??'',(string)$o['proforma_no'],implode(' ',array_column($r['items'],'description'))]),clean($q['q']??'',100)))continue;$items[]=$r;
    }return isset($q['page'])?pageResult($items,$q):array_slice($items,0,200);
}
