const {chromium,expect}=require('@playwright/test');
const env=require('./config.cjs');
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const page=await browser.newPage();await page.goto(env.APP_URL);
  const fixture={total:12500,totalAmount:8.93,currency:'USD',status:'submitted',company:{tel:'250788000000'},customer:{phone:'0788000000'},delivery:{method:'pickup'},payment:{method:'mtn',verificationStatus:'unpaid'},paymentOptions:{},momo:{enabled:false}};
  async function render(order){await page.evaluate(async o=>{const {paymentPanel}=await import('/assets/payments.js');document.querySelector('main').innerHTML=paymentPanel(o);},order);}
  for(const method of ['mtn','airtel','bank','card']){
   await render({...fixture,payment:{...fixture.payment,method}});
   await expect(page.locator('.payment-notice')).toContainText('not configured');
   await expect(page.locator('.payment-choices input')).toHaveCount(4);
   for(const width of [320,390,768,1440]){await page.setViewportSize({width,height:900});if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw new Error('Payment overflow '+method+' '+width);}
  }
  await render({...fixture,payment:{method:'bank',verificationStatus:'under_review',reference:'TEST-REF'}});
  await expect(page.locator('.payment-focus')).toContainText('Please do not pay again');await expect(page.locator('.payment-focus form')).toHaveCount(0);
  for(const state of ['verified','processing','cancelled']){await render({...fixture,payment:{...fixture.payment,verificationStatus:state}});await expect(page.locator('.payment-focus form')).toHaveCount(0);}
  await render({...fixture,payment:{...fixture.payment,method:'card'},paymentOptions:{cardPaymentUrl:'javascript:alert(1)'}});await expect(page.locator('.payment-instructions a')).toHaveCount(0);
  await render({...fixture,payment:{...fixture.payment,method:'card'},paymentOptions:{cardPaymentUrl:'https://example.test/pay'}});await expect(page.locator('.payment-instructions a')).toHaveAttribute('rel','noopener noreferrer');
  await render({...fixture,payment:{...fixture.payment,method:'bank'},paymentOptions:{bankName:'Test Bank',bankAccountName:'MATTE',bankAccountNumber:'12345'}});await expect(page.locator('[data-copy-payment]')).toHaveCount(1);
  console.log('PASS: four payment methods, 16 responsive layouts, verification states, configured instructions and secure payment links.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
