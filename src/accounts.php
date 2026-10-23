<?php
function login(array $b,bool $customerOnly=false): array {
    rate('login',10,900);$email=strtolower(clean($b['email']??'',160));$pw=is_string($b['password']??null)?$b['password']:'';if(strlen($pw)>72)fail('Password exceeds 72 bytes.');
    $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';$admin=$customerOnly?null:one('SELECT * FROM admin_users WHERE email=?',[$email]);$customer=one('SELECT * FROM customer_users WHERE email=?',[$email]);
    $a=password_verify($pw,str_replace('$2a$','$2y$',$admin['password_hash']??$dummy));$c=password_verify($pw,str_replace('$2a$','$2y$',$customer['password_hash']??$dummy));
    if($admin&&$a)return tokenFor($admin,'admin');if($customer&&$c)return tokenFor($customer,'customer');fail('Wrong email or password.',401);
}
function signup(array $b): array {rate('login',10,900);$name=clean($b['name']??'',100);$email=strtolower(clean($b['email']??'',160));$pw=is_string($b['password']??null)?$b['password']:'';if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($pw)<8||strlen($pw)>72)fail('Enter your name, valid email and password of at least 8 characters (maximum 72 bytes).');$id=insert('customer_users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash($pw,PASSWORD_BCRYPT,['cost'=>12])]);return tokenFor(['id'=>$id,'name'=>$name,'email'=>$email],'customer');}
const CUSTOMER_FIELDS='id,name,email,phone,address,latitude,longitude,created_at';
function profile(int $id): array {$r=one('SELECT '.CUSTOMER_FIELDS.' FROM customer_users WHERE id=?',[$id]);if(!$r)fail('Customer not found.',404);return $r;}
function saveProfile(int $id,array $b,bool $admin=false): array {
    profile($id);if($admin&&array_diff(array_keys($b),['name','email','phone','address']))fail('Only customer contact details can be updated here.');
    $v=['name'=>clean($b['name']??'',120),'phone'=>clean($b['phone']??'',30),'address'=>clean($b['address']??'',300)];if(!$v['name']||$v['phone']&&strlen(preg_replace('/\D/','',$v['phone']))<9)fail('Enter a name and a valid phone number.');
    if($admin){$v['email']=strtolower(clean($b['email']??'',160));if(!filter_var($v['email'],FILTER_VALIDATE_EMAIL))fail('Enter a valid email.');}else $v=[...$v,...pin($b)];update('customer_users',$v,'id=?',[$id]);return profile($id);
}
function cartItems(mixed $input): array {
    if(!is_array($input)||count($input)>160)fail('Invalid cart.');$out=[];foreach($input as $i){$id=num($i['id']??0);$qty=num($i['qty']??0);if($id<=0||$qty<=0||$qty>999)fail('Invalid cart item.');$out[$id]=min(999,($out[$id]??0)+$qty);}if(count($out)>80)fail('A cart can contain at most 80 products.');$items=[];foreach($out as $id=>$qty)$items[]=['id'=>$id,'qty'=>$qty];return $items;
}
function hydrateCart(array $items): array {$out=[];foreach($items as $i){$p=one(PRODUCT_SQL.' WHERE p.id=?',[$i['id']]);$m=$p?mapProduct($p):['id'=>$i['id'],'name'=>'Unavailable product','price'=>0,'orderable'=>false,'images'=>[]];$out[]=[...$m,'qty'=>$i['qty'],'thumb'=>$m['images'][0]['thumb']??null];}return $out;}
function getCart(int $id): array {$r=one('SELECT items FROM customer_carts WHERE customer_id=?',[$id]);return hydrateCart($r?json_decode($r['items'],true):[]);}
function saveCart(int $id,array $b,bool $merge=false): mixed {
    $items=cartItems($b['items']??[]);tx(function()use($id,&$items,$merge){sql('INSERT IGNORE INTO customer_carts(customer_id) VALUES(?)',[$id]);$old=one('SELECT items FROM customer_carts WHERE customer_id=? FOR UPDATE',[$id]);if($merge)$items=cartItems([...json_decode($old['items'],true),...$items]);sql('UPDATE customer_carts SET items=? WHERE customer_id=?',[json_encode($items),$id]);});return $merge?hydrateCart($items):['ok'=>true];
}
function customers(array $q): array {$items=array_values(array_filter(rows('SELECT '.CUSTOMER_FIELDS.' FROM customer_users ORDER BY created_at DESC,id DESC'),function($c)use($q){$shared=$c['latitude']!==null&&$c['longitude']!==null;return contains($c['name'].' '.$c['email'].' '.$c['phone'],clean($q['q']??'',100))&&(($q['location']??'')!=='shared'||$shared)&&(($q['location']??'')!=='missing'||!$shared);}));return pageResult($items,$q,'customers');}
