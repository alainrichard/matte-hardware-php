import {api,esc,card} from './app.js';
document.querySelector('.header-chat')?.addEventListener('click',()=>document.querySelector('#chat-toggle')?.click());
const syncSignup=()=>{const signup=document.querySelector('.header-signup'),signout=document.querySelector('#signout');if(signup&&signout)signup.hidden=!signout.hidden;};
if(document.querySelector('#signout'))new MutationObserver(syncSignup).observe(document.querySelector('#signout'),{attributes:true,attributeFilter:['hidden']});syncSignup();

const nav=document.querySelector('.department-nav');
let current=null,request=0,closeTimer;
const cache=new Map();
function close(restore=false){clearTimeout(closeTimer);request++;if(!current)return;const trigger=nav.querySelector(`[aria-controls="${current.id}"]`);current.hidden=true;trigger?.setAttribute('aria-expanded','false');current=null;if(restore)trigger?.focus();}
async function preview(panel,slug,name,page=1){
 const id=++request,content=panel.querySelector('.preview-content'),more=panel.querySelector('.preview-more');
 panel.dataset.preview=slug;panel.dataset.previewName=name;
 panel.querySelector('.preview-heading h2').textContent=name;
 panel.querySelector('.preview-all').href='/products?category='+encodeURIComponent(slug);
 more.disabled=true;
 if(page===1){content.innerHTML='<p role="status">Loading products…</p>';more.hidden=true;panel.querySelector('.preview-scroll').scrollTop=0;}
 try{
  const key=slug+':'+page;
  const data=cache.get(key)||await api(`/products?category=${encodeURIComponent(slug)}&page=${page}&pageSize=24`,{publicRequest:true});cache.set(key,data);
  if(id!==request||panel!==current)return;
  panel.querySelector('.preview-heading h2').textContent=`${name} (${data.total})`;
  const items=data.items.map(p=>`<a class="preview-product" href="/products/${encodeURIComponent(p.slug)}"><img loading="lazy" src="${esc(p.images?.[0]?.thumb||panel.dataset.sample)}" alt=""><span>${esc(p.name)}</span></a>`).join('');
  if(page===1)content.innerHTML=data.items.length?`<div class="preview-grid">${items}</div>`:`<div class="preview-empty"><img src="${esc(panel.dataset.sample)}" alt=""><p>Contact us for available ${esc(name.toLowerCase())}.</p><a href="/quote">Ask about this category</a></div>`;
  else content.querySelector('.preview-grid')?.insertAdjacentHTML('beforeend',items);
  more.hidden=data.page>=data.totalPages;more.dataset.page=data.page;more.textContent='Load more products';
 }catch{if(id!==request)return;if(page===1)content.innerHTML='<p role="alert">Products could not load. Please try again.</p>';more.hidden=false;more.textContent='Retry';more.dataset.page=page-1;}
 finally{if(id===request)more.disabled=false;}
}
function open(trigger){clearTimeout(closeTimer);const panel=document.getElementById(trigger.getAttribute('aria-controls'));if(panel===current)return;close();current=panel;panel.hidden=false;trigger.setAttribute('aria-expanded','true');preview(panel,panel.dataset.slug,panel.dataset.name);}
if(nav){
 const active=new URLSearchParams(location.search).get('category');
 nav.querySelectorAll('.department-trigger').forEach(trigger=>{
  const panel=document.getElementById(trigger.getAttribute('aria-controls'));
  if(active&&(trigger.dataset.department===active||[...panel.querySelectorAll('[data-preview]')].some(a=>a.dataset.preview===active)))trigger.classList.add('active');
  trigger.addEventListener('pointerenter',e=>{if(e.pointerType==='mouse')open(trigger);});
  // Mouse hover opens previews; touch and keyboard toggle them.
  trigger.addEventListener('click',e=>{if(current===panel&&(e.detail===0||e.pointerType!=='mouse'))close();else open(trigger);});
 });
 nav.addEventListener('pointerenter',()=>clearTimeout(closeTimer));
 nav.addEventListener('pointerleave',e=>{if(e.pointerType==='mouse')closeTimer=setTimeout(()=>close(),180);});
 nav.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close(true);}});
 nav.addEventListener('focusout',e=>{if(e.relatedTarget&&!nav.contains(e.relatedTarget))close();});
 nav.addEventListener('click',e=>{if(e.target.closest('.department-close'))close(true);const more=e.target.closest('.preview-more');if(more&&current)preview(current,current.dataset.preview,current.dataset.previewName,+more.dataset.page+1);});
 nav.querySelectorAll('[data-preview]').forEach(a=>{
  const show=()=>{if(current&&current.dataset.preview!==a.dataset.preview)preview(current,a.dataset.preview,a.textContent.trim());};
  a.addEventListener('pointerenter',e=>{if(e.pointerType==='mouse')show();});a.addEventListener('focus',show);
 });
 document.addEventListener('pointerdown',e=>{if(!nav.contains(e.target))close();});
}
document.querySelectorAll('.home-department').forEach(section=>{
 const rail=section.querySelector('.home-product-rail');
 section.querySelectorAll('[data-rail-move]').forEach(b=>b.addEventListener('click',()=>rail.scrollBy({left:+b.dataset.railMove*rail.clientWidth*.85,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'})));
 section.querySelector('[data-rail-more]')?.addEventListener('click',async e=>{
  const b=e.currentTarget,holder=b.parentElement;b.disabled=true;holder.querySelector('p').textContent='';
  try{const data=await api(`/products?category=${encodeURIComponent(section.dataset.category)}&page=${+b.dataset.page+1}&pageSize=24`,{publicRequest:true});holder.insertAdjacentHTML('beforebegin',data.items.map(card).join(''));b.dataset.page=data.page;if(data.page>=data.totalPages)holder.remove();}
  catch{holder.querySelector('p').textContent='Could not load products. Please try again.';}finally{b.disabled=false;}
 });
});
