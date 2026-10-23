// Read-only, repeatable-read snapshot of the ORIGINAL PostgreSQL database.
// Run: node apps/php/scripts/export-postgres.cjs [private-output-file]
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..');
require('dotenv').config({path:process.env.MATTE_SOURCE_ENV||path.resolve(__dirname,'../../api/.env'),quiet:true});
const {Client,types}=require('pg');
types.setTypeParser(1184,value=>value);
types.setTypeParser(1114,value=>value);
const tables=['categories','products','product_images','admin_users','customer_users','orders','order_items','conversations','messages','customer_carts','quote_requests','site_content','momo_payments','whatsapp_conversations','whatsapp_messages','catalog_migrations'];
(async()=>{if(!process.env.DATABASE_URL)throw new Error('Set DATABASE_URL or MATTE_SOURCE_ENV for the source PostgreSQL database.');const client=new Client({connectionString:process.env.DATABASE_URL,connectionTimeoutMillis:5000});await client.connect();try{
 await client.query('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
 const snapshot={format:'matte-postgres-v1',exportedAt:new Date().toISOString(),tables:{},sequence:13};
 const available=(await client.query("SELECT tablename FROM pg_tables WHERE schemaname='public'")).rows.map(r=>r.tablename);
 const unknown=available.filter(t=>!tables.includes(t));if(unknown.length)throw new Error('Unmapped source tables: '+unknown.join(', ')+'. Extend the migration before proceeding.');
 for(const table of tables){if(!available.includes(table))continue;snapshot.tables[table]=(await client.query(`SELECT * FROM "${table}"`)).rows;}
 const seq=await client.query('SELECT last_value,is_called FROM proforma_no_seq');snapshot.sequence=Number(seq.rows[0].last_value)+(seq.rows[0].is_called?1:0);
 await client.query('COMMIT');const destination=path.resolve(process.argv[2]||path.join(root,'storage/postgres-snapshot.json'));if(fs.existsSync(destination))throw new Error('Snapshot already exists. Choose a new output path.');const content=JSON.stringify(snapshot);fs.writeFileSync(destination,content,{mode:0o600});fs.writeFileSync(destination+'.sha256',crypto.createHash('sha256').update(content).digest('hex'));console.log('Snapshot saved. Row counts: '+JSON.stringify(Object.fromEntries(Object.entries(snapshot.tables).map(([k,v])=>[k,v.length]))));
 }finally{await client.end();}})().catch(e=>{console.error(e.message.replace(/postgres(?:ql)?:\/\/[^\s]+/g,'[database URL]'));process.exit(1);});
