<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';

$reference=trim((string)($_GET['reference']??''));
if($reference===''){flash('notice','Paystack did not return a payment reference.');redirect('index.php?page=cart');}
try{$orderId=payment_finalize_reference($reference);if(!$orderId){flash('notice','The payment is not yet successful. You can try again from checkout.');redirect('index.php?page=cart');}unset($_SESSION['pending_payment_order']);redirect('index.php?page=confirmation&id='.$orderId);}catch(Throwable $error){flash('notice',$error->getMessage());redirect('index.php?page=cart');}
