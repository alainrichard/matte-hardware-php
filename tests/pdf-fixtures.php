<?php
require dirname(__DIR__).'/src/bootstrap.php';
require ROOT.'/src/api.php';
try{
db()->beginTransaction();
$id=insert('orders',['public_token'=>b64(random_bytes(18)),'proforma_no'=>1999999999,'status'=>'proforma_issued','customer_name'=>'Long invoice layout check','customer_phone'=>'0788553011','customer_company'=>'Example construction project','customer_tin'=>'123456789','purchase_code'=>'TEST-PURCHASE','delivery_method'=>'delivery','delivery_address'=>'Kigali, Rwanda','total_rwf'=>0,'currency'=>'USD','usd_rwf_rate'=>1400,'invoice_requested'=>true]);
$total=0;for($i=1;$i<=65;$i++){$price=1400*$i;$total+=$price*2;insert('order_items',['order_id'=>$id,'description'=>'Item '.$i.' - Bathroom fittings with a deliberately long specification to test line wrapping and page continuation without hiding any quantity or amount.','unit_price_rwf'=>$price,'qty'=>2,'line_total_rwf'=>$price*2]);}
sql('UPDATE orders SET total_rwf=? WHERE id=?',[$total,$id]);file_put_contents(ROOT.'/storage/test-proforma-multipage.pdf',pdfBytes(one('SELECT * FROM orders WHERE id=?',[$id])));
db()->rollBack();echo "Multipage PDF fixture rendered; database changes rolled back.\n";
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
