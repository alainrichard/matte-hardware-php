// Build-time only. The deployed PHP application has no Node dependency.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const root = path.resolve(__dirname, '..'), repo = process.env.MATTE_SOURCE_ROOT ? path.join(path.resolve(process.env.MATTE_SOURCE_ROOT),'apps') : path.resolve(root, '..');
fs.mkdirSync(path.join(root, 'data'), {recursive:true});
const write = (name, value) => fs.writeFileSync(path.join(root, 'data', name), JSON.stringify(value,null,2));
write('site-content.json',require(path.join(repo,'api/src/site-content')).DEFAULT_CONTENT);
write('catalog.json',require(path.join(repo,'api/db/catalog')));
write('category-tree.json',require(path.join(repo,'../shared/catalog-tree')).departments);
const i18n = fs.readFileSync(path.join(repo,'web/lib/i18n.js'),'utf8');
const start=i18n.indexOf('const words = '), end=i18n.indexOf('\n};',start)+3;
const context={};vm.runInNewContext(i18n.slice(start,end)+'; result=words;',context);
write('translations.json',context.result);
fs.mkdirSync(path.join(root,'public/assets'),{recursive:true});
fs.copyFileSync(path.join(root,'data/translations.json'),path.join(root,'public/assets/translations.json'));
for (const name of ['brand','flags','category-samples','favicon.png','robots.txt']) fs.cpSync(path.join(repo,'web/public',name),path.join(root,'public',name),{recursive:true});
fs.cpSync(path.join(repo,'api/uploads'),path.join(root,'public/uploads'),{recursive:true});
console.log('Copied catalog, content, translations, branding and processed product images.');
