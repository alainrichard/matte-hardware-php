const assert=require('node:assert/strict'),env=require('./config.cjs');
(async()=>{
 let count=0;
 for(const base of [env.APP_URL,'http://localhost:8188']){
  let r=await fetch(base+'/');assert.equal(r.status,200);assert.equal(r.headers.get('x-powered-by'),null);assert.equal(r.headers.get('x-content-type-options'),'nosniff');assert.equal(r.headers.get('x-frame-options'),'DENY');assert.ok(r.headers.get('content-security-policy').includes("script-src 'self'"));count+=5;
  for(const path of ['/.env','/.git/config','/uploads/test.PHP','/uploads/test.phtml','/uploads/test.sql']){r=await fetch(base+path);assert.ok([403,404].includes(r.status),path+' '+r.status);count++;}
  r=await fetch(base+'/api/health',{headers:{Origin:'https://attacker.example'}});assert.equal(r.status,403);count++;
  r=await fetch(base+'/api/health',{headers:{'Sec-Fetch-Site':'cross-site'}});assert.equal(r.status,403);count++;
  r=await fetch(base+'/api/orders',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({padding:'a'.repeat(205000)})});assert.equal(r.status,413);count++;
  r=await fetch(base+'/api/orders',{method:'POST',headers:{'Content-Type':'application/json'},body:'{bad'});assert.equal(r.status,400);assert.equal(r.headers.get('cache-control'),'private, no-store');count+=2;
  r=await fetch(base+'/account');assert.equal(r.headers.get('cache-control'),'private, no-store');assert.equal(r.headers.get('referrer-policy'),'no-referrer');count+=2;
 }
 console.log(`PASS: ${count} security checks across PHP development server and XAMPP Apache.`);
})().catch(e=>{console.error(e);process.exit(1);});
