// Read-only regression for request-local configuration under threaded XAMPP Apache.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const env=require('dotenv').parse(fs.readFileSync(path.join(__dirname,'../.env')));
(async()=>{
 const response=await fetch(env.APP_URL+'/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:env.ADMIN_EMAIL,password:env.ADMIN_PASSWORD})});
 assert.equal(response.status,200,'Sign-in must succeed');const {token}=await response.json();assert.ok(token);
 const work=Array.from({length:8},()=>[
  fetch(env.APP_URL+'/').then(r=>assert.equal(r.status,200,'Concurrent storefront')),
  fetch(env.APP_URL+'/api/admin/stats',{headers:{Authorization:'Bearer '+token}}).then(r=>assert.equal(r.status,200,'Concurrent authenticated request'))
 ]).flat();await Promise.all(work);console.log('PASS: sign-in and 16 concurrent Apache requests retain configuration and authentication.');
})().catch(e=>{console.error(e.message);process.exit(1);});
