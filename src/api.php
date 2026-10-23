<?php
require_once __DIR__.'/catalog.php';
require_once __DIR__.'/exchange.php';
require_once __DIR__.'/accounts.php';
require_once __DIR__.'/orders.php';
require_once __DIR__.'/momo.php';
require_once __DIR__.'/chat.php';
require_once __DIR__.'/whatsapp.php';
require_once __DIR__.'/media.php';
function api(string $path,string $method,array $b,string $raw): never {
    $q=$_GET;$path=rtrim($path,'/')?:'/';
    if($path==='/api/health')json(['ok'=>true,'runtime'=>'php']);
    if($path==='/api/whatsapp/webhook'){
        if($method==='GET'){if(env('WHATSAPP_VERIFY_TOKEN')&&($_GET['hub_mode']??'')==='subscribe'&&hash_equals(env('WHATSAPP_VERIFY_TOKEN'),$_GET['hub_verify_token']??'')){header('Content-Type: text/plain');echo $_GET['hub_challenge']??'';exit;}fail('Forbidden.',403);}
        if($method==='POST'){waWebhook($raw);json(['ok'=>true]);}fail('Method not allowed.',405);
    }
    if(preg_match('~^/api/payments/momo/callback/([^/]+)$~',$path,$m)){if(!in_array($method,['POST','PUT'],true))fail('Method not allowed.',405);rate('momo-callback',30,60);if(!isUuid($m[1]))fail('Invalid reference.');momoReconcile($m[1]);json(['ok'=>true]);}
    if(in_array($path,['/api/auth/login','/api/admin/login','/api/auth/customer/login','/api/admin/customer/login'],true)&&$method==='POST')json(login($b,str_ends_with($path,'/customer/login')));
    if(in_array($path,['/api/auth/customer/signup','/api/admin/customer/signup'],true)&&$method==='POST')json(signup($b),201);
    if($method==='GET'){
        if($path==='/api/exchange-rate')json(exchangeRate());
        if($path==='/api/site-content')json(siteContent());
        if($path==='/api/settings'){$c=siteContent();json(['company'=>$c['business'],'paymentInstructions'=>$c['business']['paymentInstructions'],'requireConfirmedPrices'=>env('REQUIRE_CONFIRMED_PRICES','true')==='true']);}
        if($path==='/api/categories')json(categories());
        if($path==='/api/products')json(listProducts($q));
        if(preg_match('~^/api/products/([^/]+)$~',$path,$m)){$p=one(PRODUCT_SQL.' WHERE p.slug=? AND p.is_active=1',[$m[1]]);if(!$p||!in_array((int)$p['category_id'],visibleIds(rows('SELECT * FROM categories')),true))fail('Product not found.',404);$r=mapProduct($p);$r['related']=array_values(array_filter(listProducts(['category'=>$p['category_slug'],'limit'=>5]),fn($x)=>$x['id']!==$r['id']));$r['related']=array_slice($r['related'],0,4);$r['colors']=[];foreach(rows(PRODUCT_SQL." WHERE COALESCE(NULLIF(p.color_group,''),p.slug)=? AND p.is_active=1 ORDER BY p.id",[$r['colorGroup']]) as $color)if(in_array((int)$color['category_id'],visibleIds(rows('SELECT * FROM categories')),true))$r['colors'][]=mapProduct($color);json($r);}
    }
    if($path==='/api/orders'&&$method==='POST')json(createOrder($b),201);
    if(preg_match('~^/api/orders/([^/]+)(?:/(.+))?$~',$path,$m)){
        $token=$m[1];$action=$m[2]??'';
        if($method==='GET'&&$action==='')json(mapOrder(orderByToken($token),true));
        if($method==='GET'&&$action==='proforma.pdf'){$o=orderByToken($token);$bytes=pdfBytes($o);header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="Proforma-'.$o['proforma_no'].'.pdf"');echo $bytes;exit;}
        if($method==='POST'&&$action==='cancel')json(cancelOrder($token));
        if($method==='POST'&&in_array($action,['payment-method','payment-reference'],true))json(paymentDetails($token,$b,$action==='payment-reference'));
        if($action==='momo'){orderByToken($token);if($method==='POST')json(momoStart($token,$b['phone']??null),202);if($method==='GET'){rate('momo',30,60);$a=momoLatest($token);if($a)momoReconcile($a['reference']);json(momoPublic(momoLatest($token)));}}
    }
    if(str_starts_with($path,'/api/customer/')){$u=auth('customer');$id=$u['sub'];
        if($path==='/api/customer/profile'){if($method==='GET')json(profile($id));if($method==='PUT')json(saveProfile($id,$b));}
        if($path==='/api/customer/cart'){if($method==='GET')json(getCart($id));if($method==='PUT')json(saveCart($id,$b));}
        if($path==='/api/customer/cart/merge'&&$method==='POST')json(saveCart($id,$b,true));
        if($path==='/api/customer/invoices'&&$method==='GET')json(listOrders($q,$id));
    }
    if($path==='/api/quotes'){
        if($method==='POST'){rate('quotes',10,900);$name=clean($b['name']??'',100);$phone=clean($b['phone']??'',40);$req=textValue($b['requirement']??'',2000);if(!$name||!$phone||!$req)fail('Name, phone and requirements are required.');$pid=num($b['productId']??0)?:null;if($pid&&!one('SELECT id FROM products WHERE id=?',[$pid]))fail('Product not found.',404);$id=insert('quote_requests',['customer_name'=>$name,'customer_phone'=>$phone,'customer_email'=>clean($b['email']??'',160)?:null,'product_id'=>$pid,'quantity'=>max(1,num($b['quantity']??1,1)),'requirement'=>$req]);json(['id'=>$id,'message'=>'Your request was sent. MATTE will reply with a quote.'],201);}
        if($method==='GET'){auth('admin');json(quotes($q));}
    }
    if(preg_match('~^/api/quotes/(\d+)$~',$path,$m)&&$method==='PATCH'){auth('admin');if(!in_array($b['status']??'',['new','quoted','closed'],true))fail('Choose a valid status.');if(!one('SELECT id FROM quote_requests WHERE id=?',[$m[1]]))fail('Quote not found.',404);update('quote_requests',['status'=>$b['status']],'id=?',[$m[1]]);json(['id'=>(int)$m[1],'status'=>$b['status']]);}
    if($path==='/api/chat/conversations'&&$method==='POST'){rate('chat-create',30);$id=uuid();insert('conversations',['id'=>$id,'customer_name'=>clean($b['name']??'',80)?:null,'customer_phone'=>clean($b['phone']??'',30)?:null]);saveMessage($id,'system',['body'=>'Hello! Ask us anything about a product, size or price. A team member will reply here as soon as possible.']);json(['id'=>$id],201);}
    if(preg_match('~^/api/(chat|admin)/conversations/([^/]+)/(messages|identify|read|typing)$~',$path,$m)){
        $staff=$m[1]==='admin';if($staff)auth('admin');$id=$m[2];$action=$m[3];conversation($id);
        if($action==='messages'){if($method==='GET')json(messages($id));if($method==='POST'){rate('chat-message',60);json(saveMessage($id,$staff?'staff':'customer',$b),201);}}
        if($action==='identify'&&$method==='PATCH'&&!$staff){sql("UPDATE conversations SET customer_name=COALESCE(NULLIF(?,''),customer_name),customer_phone=COALESCE(NULLIF(?,''),customer_phone) WHERE id=?",[clean($b['name']??'',80),clean($b['phone']??'',30),$id]);json(conversation($id));}
        if($action==='read'&&$method==='POST'&&$staff){sql('UPDATE conversations SET unread_staff=0 WHERE id=?',[$id]);json(['ok'=>true]);}
        if($action==='typing'){if($method==='POST'){rate('typing',120,60);sql('INSERT INTO chat_presence(conversation_id,role,typing_until) VALUES(?,?,DATE_ADD(NOW(3),INTERVAL 5 SECOND)) ON DUPLICATE KEY UPDATE typing_until=VALUES(typing_until)',[$id,$staff?'staff':'customer']);json(['ok'=>true]);}if($method==='GET')json(['typing'=>(bool)one('SELECT role FROM chat_presence WHERE conversation_id=? AND role=? AND typing_until>NOW(3)',[$id,$staff?'customer':'staff'])]);}
    }
    if(str_starts_with($path,'/api/admin/')){
        auth('admin');$sub=substr($path,11);
        if($sub==='site-content'){if($method==='GET')json(siteContent());if($method==='PUT')json(saveContent($b));}
        if($sub==='stats'&&$method==='GET'){$orders=[];foreach(rows('SELECT status,COUNT(*) AS n,SUM(total_rwf) AS total FROM orders GROUP BY status') as $r)$orders[$r['status']]=['count'=>(int)$r['n'],'total'=>(int)$r['total']];json(['orders'=>(object)$orders,'unreadChats'=>(int)(one('SELECT SUM(unread_staff) AS n FROM conversations')['n']??0),'unconfirmedPrices'=>(int)one('SELECT COUNT(*) AS n FROM products WHERE is_active=1 AND price_confirmed=0')['n']]);}
        if($sub==='categories'){if($method==='GET')json(categories(true));if($method==='POST')json(saveCategory($b,null),201);}
        if(preg_match('~^categories/(\d+)$~',$sub,$m)){if($method==='PUT')json(saveCategory($b,(int)$m[1]));if($method==='DELETE'){if(!one('SELECT id FROM categories WHERE id=?',[$m[1]]))fail('Category not found.',404);sql('UPDATE categories SET is_active=0 WHERE id=?',[$m[1]]);json(['ok'=>true]);}}
        if($sub==='products'){if($method==='GET')json(listProducts($q,true));if($method==='POST')json(saveProduct($b,null),201);}
        if(preg_match('~^products/(\d+)(?:/images(?:/(\d+))?)?$~',$sub,$m)){$id=(int)$m[1];if(!str_contains($sub,'/images')){if($method==='GET')json(getProduct($id,true));if($method==='PUT')json(saveProduct($b,$id));if($method==='DELETE'){getProduct($id,true);sql('UPDATE products SET is_active=0,updated_at=NOW(3) WHERE id=?',[$id]);json(['ok'=>true]);}}else{if($method==='GET')json(getProduct($id,true)['images']);if($method==='POST')json(uploadImages($id),201);if($method==='DELETE'&&isset($m[2])){sql('DELETE FROM product_images WHERE product_id=? AND id=?',[$id,$m[2]]);json(getProduct($id,true));}}}
        if($sub==='orders'&&$method==='GET')json(listOrders($q));
        if(preg_match('~^orders/(\d+)$~',$sub,$m)){if($method==='PATCH')json(changeOrder((int)$m[1],$b));if($method==='GET'){$o=one('SELECT * FROM orders WHERE id=?',[$m[1]]);if(!$o)fail('Order not found.',404);json(mapOrder($o));}}
        if($sub==='customers'&&$method==='GET')json(customers($q));
        if(preg_match('~^customers/(\d+)$~',$sub,$m)){if($method==='GET')json(['customer'=>profile((int)$m[1]),'invoices'=>listOrders($q,(int)$m[1])]);if($method==='PATCH')json(saveProfile((int)$m[1],$b,true));}
        if($sub==='conversations'&&$method==='GET')json(conversations($q));
        if($sub==='whatsapp/status'&&$method==='GET')json(['enabled'=>env('WHATSAPP_ENABLED')==='true','configured'=>!waMissing(),'missing'=>waMissing(),'schemaReady'=>true,'ready'=>waReady()]);
        if($sub==='whatsapp/conversations'&&$method==='GET'){$search='%'.addcslashes(clean($q['q']??'',80),'_%\\').'%';$page=max(1,num($q['page']??1,1));$items=rows("SELECT c.*,(SELECT COALESCE(NULLIF(body,''),CONCAT(kind,' attachment')) FROM whatsapp_messages WHERE conversation_id=c.id ORDER BY created_at DESC,id DESC LIMIT 1) AS preview FROM whatsapp_conversations c WHERE phone_number_id=? AND (customer_phone LIKE ? OR customer_name LIKE ?) ORDER BY last_message_at DESC,id DESC LIMIT 31 OFFSET ?",[env('WHATSAPP_PHONE_NUMBER_ID'),$search,$search,($page-1)*30]);json(['items'=>array_slice($items,0,30),'hasMore'=>count($items)>30,'page'=>$page]);}
        if(preg_match('~^whatsapp/conversations/(\d+)/(messages|read)$~',$sub,$m)){$id=(int)$m[1];$c=waConversation($id);if($m[2]==='read'&&$method==='POST'){sql('UPDATE whatsapp_conversations SET unread=0 WHERE id=?',[$id]);json(['ok'=>true]);}if($m[2]==='messages'){if($method==='POST')json(waSend($id,$b),201);if($method==='GET'){sql("UPDATE whatsapp_messages SET status='unknown' WHERE conversation_id=? AND status='sending' AND created_at<DATE_SUB(NOW(3),INTERVAL 2 MINUTE)",[$id]);$before=num($q['before']??0);$items=rows('SELECT * FROM whatsapp_messages WHERE conversation_id=?'.($before?' AND id<?':'').' ORDER BY id DESC LIMIT 101',$before?[$id,$before]:[$id]);json(['conversation'=>$c,'canReply'=>waAllowed($c['last_customer_at']),'hasMore'=>count($items)>100,'items'=>array_reverse(array_slice($items,0,100))]);}}}
        if(preg_match('~^whatsapp/messages/(\d+)/attachment$~',$sub,$m)&&$method==='GET')waAttachment((int)$m[1]);
    }
    fail('Not found.',404);
}
