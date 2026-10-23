const {chromium,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const output=path.join(__dirname,'../storage/browser/departments');fs.mkdirSync(output,{recursive:true});
(async()=>{const browser=await chromium.launch({channel:'msedge',headless:true});let checks=0;const errors=[];
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:8188');await page.waitForLoadState('networkidle');
 const triggers=page.locator('.department-trigger');if(await triggers.count()<2)throw Error('Missing departments');
 await triggers.first().hover();await expect(page.locator('.department-panel:visible')).toBeVisible();await expect(page.locator('.department-panel:visible .preview-content')).not.toContainText('Loading products');checks++;
 const panel=page.locator('.department-panel:visible'),child=panel.locator('[data-preview]').first();
 if(await child.count()){await child.focus();await expect(panel.locator('.preview-all')).toHaveAttribute('href',await child.getAttribute('href'));await expect(panel.locator('.preview-content')).not.toContainText('Loading products');checks++;}
 await page.screenshot({path:path.join(output,'desktop-menu.png')});
 await page.keyboard.press('Escape');await expect(triggers.first()).toBeFocused();await expect(page.locator('.department-panel:visible')).toHaveCount(0);checks++;
 await page.mouse.move(0,0);await triggers.first().focus();await page.keyboard.press('Enter');await expect(page.locator('.department-panel:visible')).toHaveCount(1);await page.keyboard.press('Escape');checks++;
 const categories=await (await page.request.get('http://localhost:8188/api/categories')).json();
 for(const category of categories){const selector=category.parentId===null?`[data-department="${category.slug}"]`:`[data-preview="${category.slug}"]`;await expect(page.locator(selector)).toHaveCount(1);}checks++;
 await page.screenshot({path:path.join(output,'desktop-home.png')});
 const rail=page.locator('.home-product-rail').first();await rail.scrollIntoViewIfNeeded();await page.locator('[data-rail-move="1"]').first().click();await expect.poll(()=>rail.evaluate(el=>el.scrollLeft)).toBeGreaterThan(0);checks++;
 await page.locator('.category-grid').screenshot({path:path.join(output,'department-cards.png')});
 for(const width of [320,390,768,1024]){
  const context=await browser.newContext({viewport:{width,height:844},hasTouch:true,isMobile:width<768});const m=await context.newPage();m.on('pageerror',e=>errors.push(e.message));
  await m.goto('http://localhost:8188');await m.waitForLoadState('networkidle');
  if(await m.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Page overflow at '+width);
  const trigger=m.locator('.department-trigger').first();await trigger.tap();await expect(m.locator('.department-panel:visible')).toHaveCount(1);await expect(m.locator('.department-panel:visible .preview-content')).not.toContainText('Loading products');await expect(m.locator('.department-panel:visible .preview-product').first()).toBeVisible();
  if(await m.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Menu overflow at '+width);
  await m.screenshot({path:path.join(output,`menu-${width}.png`)});await trigger.tap();await expect(m.locator('.department-panel:visible')).toHaveCount(0);
  await m.screenshot({path:path.join(output,`home-${width}.png`)});checks+=3;
  await m.goto('http://localhost:8188/products?category=sanitaryware');await expect(m.locator('.department-trigger.active')).toHaveCount(1);checks++;
  await context.close();
 }
 await page.goto('http://localhost:8188');await page.evaluate(()=>localStorage.setItem('matte.theme','dark'));await page.reload();await expect(page.locator('body')).toHaveClass(/dark/);await page.locator('.department-trigger').first().hover();await expect(page.locator('.department-panel:visible .preview-product').first()).toBeVisible();await page.screenshot({path:path.join(output,'dark-menu.png')});checks++;
 if(errors.length)throw Error(errors.join('\n'));fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({checks,errors},null,2));console.log(`PASS: ${checks} department navigation, keyboard, touch, carousel and responsive checks.`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
