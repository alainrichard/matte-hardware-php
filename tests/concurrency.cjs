const env=require('./config.cjs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const primary=env.APP_URL,secondary='http://127.0.0.1:8190';
async function call(base,url,body,token){const r=await fetch(base+'/api'+url,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined});return {status:r.status,data:await r.json()};}
(async()=>{
const login=await call(primary,'/auth/login',{email:env.ADMIN_EMAIL,password:env.ADMIN_PASSWORD});assert.equal(login.status,200);const token=login.data.token;
const cats=(await call(primary,'/admin/categories',null,token)).data;const p=await call(primary,'/admin/products',{name:'Concurrent stock '+crypto.randomUUID(),categoryId:cats.find(c=>c.active&&!c.parentId).id,price:1000,priceConfirmed:true,stockQuantity:1,active:true},token);assert.equal(p.status,201);
const body={customer:{name:'Concurrency fixture',phone:'0788553011'},invoiceRequested:false,items:[{productId:p.data.id,qty:1}]};
const results=await Promise.all([call(primary,'/orders',body),call(secondary,'/orders',body)]);assert.deepEqual(results.map(r=>r.status).sort(),[201,422]);
const order=results.find(r=>r.status===201).data;assert.equal((await call(primary,'/admin/products/'+p.data.id,null,token)).data.stockQuantity,0);
const cancelled=await Promise.all([call(primary,'/orders/'+order.token+'/cancel',{}),call(secondary,'/orders/'+order.token+'/cancel',{})]);assert.ok(cancelled.every(r=>r.status===200));assert.equal((await call(primary,'/admin/products/'+p.data.id,null,token)).data.stockQuantity,1);
console.log('PASS: concurrent checkouts cannot oversell; concurrent cancellation restores stock exactly once across two PHP processes.');
})().catch(e=>{console.error(e);process.exit(1);});
