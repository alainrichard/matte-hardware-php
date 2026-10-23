<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if(!str_ends_with(env('DB_NAME'),'_test'))throw new RuntimeException('Use an isolated _test database.');
$count=0;
function checkSecurity(bool $ok): void {global $count;if(!$ok)throw new RuntimeException('Security assertion failed.');$count++;}
function rejectedToken(string $token): void {$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;try{auth();throw new RuntimeException('Invalid token accepted.');}catch(HttpError $e){checkSecurity($e->status===401);}}
try{
 db()->beginTransaction();
 $id=insert('customer_users',['name'=>'Security fixture','email'=>uuid().'@example.test','password_hash'=>password_hash('Security-fixture-123',PASSWORD_BCRYPT)]);
 $user=['id'=>$id,'name'=>'Security fixture','email'=>'fixture@example.test'];$token=tokenFor($user,'customer')['token'];
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;checkSecurity(auth('customer')['sub']===$id);
 rejectedToken($token.'tampered');rejectedToken('bad.token');rejectedToken(str_repeat('a',9000));
 $parts=explode('.',$token);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
 foreach(['expired','invalid-exp','future','wrong-role','missing-version'] as $case){
  $p=$claims;if($case==='expired')$p['exp']=time()-1;if($case==='invalid-exp')$p['exp']='tomorrow';if($case==='future')$p['iat']=time()+3600;if($case==='wrong-role')$p['role']='owner';if($case==='missing-version')unset($p['credentialVersion']);
  $body=b64(json_encode($p));rejectedToken($parts[0].'.'.$body.'.'.b64(hash_hmac('sha256',$parts[0].'.'.$body,jwtSecret(),true)));
 }
 sql('UPDATE customer_users SET password_hash=? WHERE id=?',[password_hash('New-fixture-password',PASSWORD_BCRYPT),$id]);rejectedToken($token);
 $new=tokenFor($user,'customer')['token'];$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$new;checkSecurity(auth('customer')['sub']===$id);
 sql('DELETE FROM customer_users WHERE id=?',[$id]);rejectedToken($new);
 echo "PASS: $count authentication security checks; fixtures rolled back.\n";
}finally{if(db()->inTransaction())db()->rollBack();}
