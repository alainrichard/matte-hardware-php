// Read-only snapshot from the existing Compose API container, plus its upload volume.
// No database credentials or private records are printed to the console.
const {spawnSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),repo=path.resolve(process.env.MATTE_SOURCE_ROOT||path.join(root,'../..'));
const destination=path.resolve(process.argv[2]||path.join(root,'storage','docker-snapshot-'+Date.now()+'.json'));
const dockerArgs=['--config',path.join(repo,'.docker-local'),'compose','-f',path.join(repo,'docker-compose.yml')];
const script=`
const {Client,types}=require('pg');
types.setTypeParser(1184,value=>value);
types.setTypeParser(1114,value=>value);
(async()=>{
 const client=new Client({connectionString:process.env.DATABASE_URL,connectionTimeoutMillis:10000});
 const tables=['categories','products','product_images','admin_users','customer_users','orders','order_items','conversations','messages','customer_carts','quote_requests','site_content','momo_payments','whatsapp_conversations','whatsapp_messages','catalog_migrations'];
 await client.connect();try{
  await client.query('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
  const available=(await client.query("SELECT tablename FROM pg_tables WHERE schemaname='public'")).rows.map(r=>r.tablename);
  const unknown=available.filter(t=>!tables.includes(t));if(unknown.length)throw new Error('Unmapped source tables: '+unknown.join(', '));
  const out={format:'matte-postgres-v1',exportedAt:new Date().toISOString(),tables:{},sequence:13};
  for(const table of tables)if(available.includes(table))out.tables[table]=(await client.query('SELECT * FROM "'+table+'"')).rows;
  const seq=(await client.query('SELECT last_value,is_called FROM proforma_no_seq')).rows[0];out.sequence=Number(seq.last_value)+(seq.is_called?1:0);
  await client.query('COMMIT');process.stdout.write(JSON.stringify(out));
 }finally{await client.end();}
})().catch(e=>{console.error(e.message);process.exit(1);});`;
function run(args){const result=spawnSync('docker',[...dockerArgs,...args],{cwd:repo,encoding:'utf8',maxBuffer:256*1024*1024,timeout:120000,windowsHide:true});if(result.error)throw result.error;if(result.status!==0)throw new Error(result.stderr||'Docker command failed');return result.stdout;}
try{
 if(fs.existsSync(destination))throw new Error('Destination exists. Choose a new snapshot file.');
 const raw=run(['exec','-T','api','node','-e',script]),snapshot=JSON.parse(raw);
 fs.mkdirSync(path.dirname(destination),{recursive:true});fs.writeFileSync(destination,raw,{mode:0o600});fs.writeFileSync(destination+'.sha256',crypto.createHash('sha256').update(raw).digest('hex'));
 const uploadDestination=path.join(root,'public/uploads');fs.mkdirSync(uploadDestination,{recursive:true});run(['cp','api:/app/uploads/.',uploadDestination]);
 console.log('Docker snapshot and uploaded photos saved. Row counts: '+JSON.stringify(Object.fromEntries(Object.entries(snapshot.tables).map(([k,v])=>[k,v.length]))));
 console.log('Snapshot: '+destination);
}catch(e){console.error(e.message);process.exit(1);}
