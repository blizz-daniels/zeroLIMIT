<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';
header('Content-Type: application/json; charset=utf-8');
try{payment_migrate();$ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??''))))));if(!$ids||count($ids)>30)throw new RuntimeException('Invalid product selection.');$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=db()->prepare("SELECT id,price,discount_price,recipient_id FROM products WHERE id IN ($marks) AND status='active'");$stmt->execute($ids);$out=[];foreach($stmt->fetchAll() as $product){if(!$product['recipient_id']||!payment_get_recipient((int)$product['recipient_id']))continue;$base=$product['discount_price']!==null?(string)$product['discount_price']:(string)$product['price'];$quote=payment_quote_naira($base);$out[$product['id']]=['price'=>payment_money_kobo($quote['gross_kobo']),'charges'=>payment_money_kobo($quote['charge_kobo'])];}echo json_encode(['status'=>true,'quotes'=>$out],JSON_THROW_ON_ERROR);}catch(Throwable $error){http_response_code(422);echo json_encode(['status'=>false,'message'=>'Pricing is unavailable.'],JSON_THROW_ON_ERROR);}
