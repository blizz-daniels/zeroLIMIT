<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';

$payload=file_get_contents('php://input')?:'';$signature=$_SERVER['HTTP_X_PAYSTACK_SIGNATURE']??'';$secret=payment_setting('paystack_secret_key','');
if($secret===''||!hash_equals(hash_hmac('sha512',$payload,$secret),$signature)){http_response_code(401);exit;}
$event=json_decode($payload,true);if(($event['event']??'')==='charge.success'&&!empty($event['data']['reference'])){try{payment_finalize_reference((string)$event['data']['reference']);}catch(Throwable $error){error_log('Paystack webhook: '.$error->getMessage());http_response_code(500);exit;}}
http_response_code(200);
