<?php
function normalizeContent(mixed $input,mixed $shape): mixed {
    if(is_string($shape))return is_string($input)?textValue($input):$shape;
    if(is_bool($shape))return is_bool($input)?$input:$shape;
    if(is_numeric($shape))return is_numeric($input)&&(float)$input>0?min(100000000,(float)$input):$shape;
    if(!is_array($shape))return $shape;
    if(!is_array($input))return $shape;
    $out=[];foreach($shape as $k=>$v){
        if($k==='groups'&&is_array($input[$k]??null)){$out[$k]=[];foreach(array_slice($input[$k],0,40) as $g){if(!is_array($g))continue;$title=clean($g['title']??'',100);if($title)$out[$k][]=['title'=>$title,'column'=>max(0,min(3,num($g['column']??0))),'items'=>array_values(array_filter(array_map(fn($i)=>clean($i,100),array_slice(is_array($g['items']??null)?$g['items']:[],0,30))))];} }
        else $out[$k]=normalizeContent($input[$k]??null,$v);
    }return $out;
}
function siteContent(): array {$default=json_decode(file_get_contents(ROOT.'/data/site-content.json'),true);$r=one('SELECT content FROM site_content WHERE id=1');return normalizeContent($r?json_decode($r['content'],true):null,$default);}
function saveContent(array $b): array {
    $c=normalizeContent($b,json_decode(file_get_contents(ROOT.'/data/site-content.json'),true));
    foreach(['cardPaymentUrl','airtelPaymentUrl','purchaseCodeUrl'] as $key){$v=$c['business'][$key]??'';if($v&&(!filter_var($v,FILTER_VALIDATE_URL)||parse_url($v,PHP_URL_SCHEME)!=='https'||parse_url($v,PHP_URL_USER)||parse_url($v,PHP_URL_PASS)))fail($key.' must be an HTTPS URL without credentials.');}
    foreach(['mtnUssdCode','airtelUssdCode'] as $key)if($c['business'][$key]&&!preg_match('/^\*[0-9*]{2,80}#$/',$c['business'][$key]))fail('Enter a valid payment USSD code.');
    foreach(['logoUrl','lightLogoUrl'] as $key){$v=$c['branding'][$key];if(!preg_match('~^/(?!/)~',$v)&&!(filter_var($v,FILTER_VALIDATE_URL)&&parse_url($v,PHP_URL_SCHEME)==='https'))fail('Logo must use a local path or HTTPS URL.');}
    foreach(['accentColor','accentDarkColor'] as $key)if(!preg_match('/^#[0-9a-f]{6}$/i',$c['branding'][$key]))fail('Use six-digit hex colors.');
    sql('INSERT INTO site_content(id,content) VALUES(1,?) ON DUPLICATE KEY UPDATE content=VALUES(content),updated_at=NOW(3)',[json_encode($c)]);return $c;
}
function categoryIds(array $cats,int $id): array {$ids=[$id];do{$old=count($ids);foreach($cats as $c)if(in_array((int)$c['parent_id'],$ids,true)&&!in_array((int)$c['id'],$ids,true))$ids[]=(int)$c['id'];}while(count($ids)>$old);return $ids;}
function visibleIds(array $cats): array {$ids=[];do{$old=count($ids);foreach($cats as $c)if($c['is_active']&&($c['parent_id']===null||in_array((int)$c['parent_id'],$ids,true))&&!in_array((int)$c['id'],$ids,true))$ids[]=(int)$c['id'];}while(count($ids)>$old);return $ids;}
function categories(bool $admin=false): array {
    $cats=rows('SELECT * FROM categories ORDER BY sort,id');$visible=visibleIds($cats);$out=[];
    foreach($cats as $c){if(!$admin&&!in_array((int)$c['id'],$visible,true))continue;$ids=$admin?[(int)$c['id']]:array_values(array_intersect(categoryIds($cats,(int)$c['id']),$visible));$marks=implode(',',array_fill(0,count($ids),'?'));
        $count=one("SELECT COUNT(*) AS n FROM products WHERE is_active=1 AND category_id IN ($marks)",$ids)['n'];
        $img=one("SELECT pi.base_name FROM product_images pi JOIN products p ON p.id=pi.product_id JOIN categories cc ON cc.id=p.category_id WHERE p.is_active=1 AND p.category_id IN ($marks) ORDER BY (p.category_id=?) DESC,cc.sort,cc.id,p.is_featured DESC,p.id,pi.sort LIMIT 1",[...$ids,$c['id']]);
        $out[]=['id'=>(int)$c['id'],'slug'=>$c['slug'],'name'=>$c['name'],'blurb'=>$c['blurb'],'sort'=>(int)$c['sort'],'parentId'=>$c['parent_id']===null?null:(int)$c['parent_id'],'imageUrl'=>$c['image_url'],'active'=>(bool)$c['is_active'],'count'=>(int)$count,'product_count'=>(int)$count,'cover'=>$img?'/uploads/products/'.$img['base_name'].'-sm.jpg':null];
    }return $out;
}
function mapProduct(array $p,bool $admin=false): array {
    $images=rows('SELECT id,base_name FROM product_images WHERE product_id=? ORDER BY sort,id',[$p['id']]);$confirmed=env('REQUIRE_CONFIRMED_PRICES','true')==='true';$price=$admin||$p['price_confirmed']||!$confirmed;
    return ['id'=>(int)$p['id'],'slug'=>$p['slug'],'name'=>$p['name'].(!$admin&&$p['color_name']?' - '.$p['color_name']:''),'colorName'=>$p['color_name'],'colorGroup'=>$p['color_group']?:$p['slug'],'summary'=>$p['summary'],'description'=>$p['description'],'specs'=>json_decode($p['specs'])?:new stdClass(),'price'=>$price?(int)$p['price_rwf']:null,'priceConfirmed'=>(bool)$p['price_confirmed'],'unit'=>$p['unit'],'inStock'=>(int)$p['stock_quantity']>0,'stockQuantity'=>(int)$p['stock_quantity'],'featured'=>(bool)$p['is_featured'],'active'=>(bool)$p['is_active'],'orderable'=>(bool)($p['is_active']&&$p['stock_quantity']>0&&$p['price_rwf']>0&&($p['price_confirmed']||!$confirmed)),'category'=>['id'=>(int)$p['category_id'],'name'=>$p['category_name'],'slug'=>$p['category_slug']],'images'=>array_map(fn($r)=>['url'=>'/uploads/products/'.$r['base_name'].'.jpg','thumb'=>'/uploads/products/'.$r['base_name'].'-sm.jpg',...($admin?['id'=>(int)$r['id']]:[])],$images),...($admin?['imageNames'=>array_column($images,'base_name')]:[])];
}
const PRODUCT_SQL='SELECT p.*,c.name AS category_name,c.slug AS category_slug FROM products p JOIN categories c ON c.id=p.category_id';
function getProduct(int $id,bool $admin=false): array {$p=one(PRODUCT_SQL.' WHERE p.id=?',[$id]);if(!$p)fail('Product not found.',404);return mapProduct($p,$admin);}
function listProducts(array $q,bool $admin=false): mixed {
    $cats=rows('SELECT * FROM categories');$visible=visibleIds($cats);$selected=null;
    if(!empty($q['category']))foreach($cats as $c)if($admin?(int)$c['id']===num($q['category']):$c['slug']===$q['category'])$selected=$admin?[(int)$c['id']]:categoryIds($cats,(int)$c['id']);
    $items=[];foreach(rows(PRODUCT_SQL) as $p){
        if(!$admin&&(!$p['is_active']||!in_array((int)$p['category_id'],$visible,true)))continue;
        if(!empty($q['category'])&&(!$selected||!in_array((int)$p['category_id'],$selected,true)))continue;
        if(!contains(implode(' ',[$p['name'],$p['slug'],$p['summary'],$p['description'],$p['specs'],$p['category_name']]),clean($q['q']??'',100)))continue;
        if(($q['featured']??'')==='1'&&!$p['is_featured'])continue;
        $stock=$q[$admin?'stock':'availability']??'';if(($stock==='in'&&$p['stock_quantity']<=0)||($stock==='out'&&$p['stock_quantity']>0)||($stock==='low'&&($p['stock_quantity']<1||$p['stock_quantity']>5)))continue;
        if($admin){if(($q['visibility']??'')==='active'&&!$p['is_active']||($q['visibility']??'')==='hidden'&&$p['is_active'])continue;if(($q['price']??'')==='confirmed'&&!$p['price_confirmed']||($q['price']??'')==='unconfirmed'&&$p['price_confirmed'])continue;}
        $items[]=mapProduct($p,$admin);
    }
    $facets=[];foreach($items as $p)foreach((array)$p['specs'] as $k=>$v)if($v!=='')$facets[$k][(string)$v]=($facets[$k][(string)$v]??0)+1;
    foreach((array)($q['facet']??[]) as $f){if(!is_string($f)||!str_contains($f,':'))continue;[$k,$v]=explode(':',$f,2);$items=array_values(array_filter($items,fn($p)=>mb_strtolower((string)(((array)$p['specs'])[$k]??''))===mb_strtolower($v)));}
    $categorySort=array_column($cats,'sort','id');
    usort($items,match($q['sort']??'featured'){'price_asc'=>fn($a,$b)=>($a['price']??PHP_INT_MAX)<=>($b['price']??PHP_INT_MAX),'price_desc'=>fn($a,$b)=>($b['price']??-1)<=>($a['price']??-1),'name'=>fn($a,$b)=>strcasecmp($a['name'],$b['name']),default=>fn($a,$b)=>($b['featured']<=>$a['featured'])?:(($categorySort[$a['category']['id']]??0)<=>($categorySort[$b['category']['id']]??0))?:($a['id']<=>$b['id'])});
    if(isset($q['page'])){$r=pageResult($items,$q);$r['facets']=[];foreach($facets as $k=>$vs){$values=[];foreach($vs as $v=>$count)$values[]=['value'=>(string)$v,'count'=>$count];$r['facets'][]=['name'=>$k,'values'=>$values];}$r['unconfirmedPrices']=(int)one('SELECT COUNT(*) AS n FROM products WHERE is_active=1 AND price_confirmed=0')['n'];return $r;}
    return array_slice($items,0,max(1,min(200,num($q['limit']??200,200))));
}
function saveCategory(array $b,?int $id): array {
    $name=clean($b['name']??'',100);$slug=slug(clean($b['slug']??$name));if(!$name||!$slug)fail('Category name and URL name are required.');
    $parent=empty($b['parentId'])?null:num($b['parentId']);$cats=rows('SELECT * FROM categories');
    if($id&&!one('SELECT id FROM categories WHERE id=?',[$id]))fail('Category not found.',404);
    if($parent!==null&&(!one('SELECT id FROM categories WHERE id=?',[$parent])||$id&&in_array($parent,categoryIds($cats,$id),true)))fail('Choose a parent outside this category and its descendants.');
    $image=$b['imageUrl']??null;if($image&&!preg_match('~^/category-samples/(sanitaryware|electrical|hardware|tiles|water-purifier|plumbing|water-heaters|machines-pumps)\.webp$~',$image))fail('Choose an available category sample image.');
    $v=['name'=>$name,'slug'=>$slug,'blurb'=>textValue($b['blurb']??'',500),'sort'=>max(0,num($b['sort']??0)),'parent_id'=>$parent,'image_url'=>$image,'is_active'=>($b['active']??true)!==false];
    if($id)update('categories',$v,'id=?',[$id]);else $id=insert('categories',$v);return array_values(array_filter(categories(true),fn($c)=>$c['id']===$id))[0];
}
function saveProduct(array $b,?int $id): array {
    if($id)getProduct($id,true);$name=clean($b['name']??'',160);$cat=num($b['categoryId']??0);if(!$name||!one('SELECT id FROM categories WHERE id=?',[$cat]))fail('Name and a valid category are required.');
    $specs=[];foreach(array_slice((array)($b['specs']??[]),0,30,true) as $k=>$v){if(is_scalar($v)&&clean((string)$k,60)&&clean((string)$v,200))$specs[clean((string)$k,60)]=clean((string)$v,200);}
    $qty=max(0,num($b['stockQuantity']??0));$v=['name'=>$name,'category_id'=>$cat,'summary'=>clean($b['summary']??'',240),'description'=>textValue($b['description']??''),'specs'=>json_encode((object)$specs),'price_rwf'=>max(0,num($b['price']??0)),'price_confirmed'=>!empty($b['priceConfirmed']),'unit'=>clean($b['unit']??'piece',30)?:'piece','stock_quantity'=>$qty,'in_stock'=>$qty>0,'is_featured'=>!empty($b['featured']),'is_active'=>($b['active']??true)!==false,'color_group'=>slug(clean($b['colorGroup']??'',80))?:null,'color_name'=>clean($b['colorName']??'',60),'updated_at'=>gmdate('Y-m-d H:i:s')];
    if($id)update('products',$v,'id=?',[$id]);else{$base=slug($name)?:'product';$s=$base;$i=1;while(one('SELECT id FROM products WHERE slug=?',[$s]))$s=$base.'-'.++$i;$v['slug']=$s;$id=insert('products',$v);}return getProduct($id,true);
}
