// Fresh isolated test configuration; never targets the migrated store database.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),file=path.join(root,'storage/test.env');
if(!fs.existsSync(file)){
 let text=fs.readFileSync(path.join(root,'.env.example'),'utf8');
 const overrides={APP_URL:'http://127.0.0.1:8189',DB_PORT:'3306',DB_NAME:'matte_store_test',DB_USER:'root',DB_PASSWORD:'',ADMIN_EMAIL:'test-admin@matte.invalid',ADMIN_PASSWORD:crypto.randomBytes(24).toString('hex'),JWT_SECRET:crypto.randomBytes(48).toString('hex'),MOMO_ENABLED:'false',WHATSAPP_ENABLED:'false',MAIL_WORKER_ENABLED:'false'};
 for(const [key,value] of Object.entries(overrides))text=text.replace(new RegExp('^'+key+'=.*$','m'),()=>key+'='+value);
 fs.writeFileSync(file,text,{mode:0o600});
}
console.log('Isolated test configuration ready.');
