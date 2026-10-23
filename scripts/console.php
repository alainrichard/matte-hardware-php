<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
require ROOT.'/src/api.php';
$command=$argv[1]??'help';
try{
    switch($command){
    case 'init':
        db()->exec(file_get_contents(ROOT.'/database/schema.sql'));echo "MariaDB schema ready. Existing records retained.\n";break;
    case 'seed':
        tx(function(){
            $data=json_decode(file_get_contents(ROOT.'/data/catalog.json'),true,512,JSON_THROW_ON_ERROR);$ids=[];
            foreach($data['categories'] as $i=>$c){sql('INSERT IGNORE INTO categories(slug,name,blurb,sort) VALUES(?,?,?,?)',[$c['slug'],$c['name'],$c['blurb'],$i]);$ids[$c['slug']]=(int)one('SELECT id FROM categories WHERE slug=?',[$c['slug']])['id'];}
            foreach($data['products'] as $p){if(one('SELECT id FROM products WHERE slug=?',[$p['slug']]))continue;$id=insert('products',['slug'=>$p['slug'],'category_id'=>$ids[$p['category']],'name'=>$p['name'],'summary'=>$p['summary'],'description'=>$p['description'],'specs'=>json_encode((object)$p['specs']),'price_rwf'=>$p['price'],'price_confirmed'=>false,'stock_quantity'=>1,'in_stock'=>true,'is_featured'=>$p['featured']??false]);foreach($p['images'] as $i=>$base)if(is_file(ROOT.'/public/uploads/products/'.$base.'.jpg')&&is_file(ROOT.'/public/uploads/products/'.$base.'-sm.jpg'))insert('product_images',['product_id'=>$id,'base_name'=>$base,'sort'=>$i]);}
            $tree=json_decode(file_get_contents(ROOT.'/data/category-tree.json'),true);$visit=function($nodes,$parent=null)use(&$visit){foreach($nodes as $i=>$node){$slug=$node['slug'];$existing=one('SELECT id FROM categories WHERE slug=?',[$slug]);if(!$existing){$id=insert('categories',['slug'=>$slug,'name'=>$node['name']??$node['title']??$slug,'sort'=>$i,'parent_id'=>$parent]);}else{$id=(int)$existing['id'];if($parent)sql('UPDATE categories SET parent_id=? WHERE id=? AND parent_id IS NULL',[$parent,$id]);}if(!empty($node['children']))$visit($node['children'],$id);}};$visit($tree);
        });echo "Starter catalog ready. Prices remain unconfirmed; existing products retained.\n";break;
    case 'admin':
        $email=strtolower(env('ADMIN_EMAIL'));$password=env('ADMIN_PASSWORD');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>72)throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD (12–72 bytes) in .env.');
        sql('INSERT INTO admin_users(email,name,password_hash) VALUES(?,\'Admin\',?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)',[$email,password_hash($password,PASSWORD_BCRYPT,['cost'=>12])]);echo "Admin account provisioned.\n";break;
    case 'worker':
        momoPoll();processMail();sql('DELETE FROM rate_limits WHERE expires_at<NOW()');sql('DELETE FROM chat_presence WHERE typing_until<DATE_SUB(NOW(),INTERVAL 1 DAY)');echo "Payment reconciliation and notification queue processed.\n";break;
    case 'check':
        echo json_encode(['php'=>PHP_VERSION,'database'=>db()->getAttribute(PDO::ATTR_SERVER_VERSION),'secretConfigured'=>strlen(env('JWT_SECRET'))>=32,'pdf'=>class_exists(Dompdf\Dompdf::class),'smtpConfigured'=>(bool)env('SMTP_HOST'),'momoEnabled'=>env('MOMO_ENABLED')==='true','momoMissing'=>momoErrors(),'whatsappEnabled'=>env('WHATSAPP_ENABLED')==='true','whatsappMissing'=>waMissing()],JSON_PRETTY_PRINT)."\n";break;
    default:echo "php scripts/console.php init|seed|admin|worker|check\n";
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
