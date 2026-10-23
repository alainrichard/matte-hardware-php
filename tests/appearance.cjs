// Read-only storefront checks. Cart changes stay in an isolated browser profile.
const {chromium,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const base='http://localhost:8188',dir=path.join(__dirname,'../storage/browser/appearance');fs.mkdirSync(dir,{recursive:true});
(async()=>{const browser=await chromium.launch({channel:'msedge',headless:true});const errors=[];let checks=0;
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base);await page.waitForLoadState('networkidle');
 for(const [code,label] of [['fr','Français'],['rw','Kinyarwanda'],['en','English']]){
  await page.getByRole('button',{name:label,exact:true}).click();await expect(page.locator('html')).toHaveAttribute('lang',code);await expect(page.locator(`[data-language="${code}"]`)).toHaveAttribute('aria-pressed','true');await page.reload();await expect(page.locator('html')).toHaveAttribute('lang',code);checks++;
 }
 for(const name of ['dark','light','system']){await page.locator(`[data-theme="${name}"]`).click();await expect(page.locator(`[data-theme="${name}"]`)).toHaveAttribute('aria-pressed','true');checks++;}
 await page.locator('[data-theme="light"]').click();
 const products=await (await page.request.get(base+'/api/products?limit=200')).json(),product=products.find(p=>p.orderable)||products[0];
 await page.goto(base+'/products/'+product.slug);await expect(page.locator('.product-order')).toBeVisible();await page.locator('.photo-zoom').click();await expect(page.locator('dialog')).toBeVisible();await page.getByRole('button',{name:'Close',exact:true}).click();checks++;
 if(product.orderable){await page.locator('.product-order [data-add]').click();await expect(page.locator('#cart-count')).toHaveText('1');checks++;}
 const routes=['/','/products','/products?category=sanitaryware','/products/'+product.slug,'/about','/signin','/signup','/quote','/cart','/checkout'];
 for(const width of [1440,768,390,320]){
  await page.setViewportSize({width,height:1000});
  for(let i=0;i<routes.length;i++){
   await page.goto(base+routes[i]);await page.waitForLoadState('networkidle');await expect(page.locator('h1').first()).toBeVisible();
   if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Horizontal overflow '+width+' '+routes[i]);
   if((await page.locator('body').innerText()).includes('Unable to load this page'))throw Error('Page failed '+routes[i]);
   await page.screenshot({path:path.join(dir,`${width}-${i}.png`)});checks++;
  }
  await page.locator('footer').screenshot({path:path.join(dir,`${width}-footer.png`)});checks++;
 }
 await page.setViewportSize({width:390,height:844});await page.goto(base+'/products');await page.locator('[data-theme="dark"]').click();await expect(page.locator('body')).toHaveClass(/dark/);await page.screenshot({path:path.join(dir,'dark-mobile.png')});checks++;
 if(errors.length)throw Error(errors.join('\n'));fs.writeFileSync(path.join(dir,'results.json'),JSON.stringify({checks,errors},null,2));console.log(`PASS: ${checks} flags, persistence, themes, gallery, cart and responsive page checks.`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
