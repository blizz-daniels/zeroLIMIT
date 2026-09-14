<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/payments_flow.php';

payment_migrate();
$orderId = (int)($_GET['order'] ?? 0);
if (!$orderId || (int)($_SESSION['pending_payment_order'] ?? 0) !== $orderId) { flash('notice','Your secure payment session has expired. Please begin checkout again.'); redirect('index.php?page=cart'); }
$stmt = db()->prepare('SELECT o.*,c.email,r.subaccount_code,pt.id transaction_id,pt.reference_code,pt.status transaction_status,pt.raw_response FROM orders o JOIN customers c ON c.id=o.customer_id JOIN payment_recipients r ON r.id=o.recipient_id JOIN payment_transactions pt ON pt.order_id=o.id WHERE o.id=?'); $stmt->execute([$orderId]); $order=$stmt->fetch();
if (!$order || !preg_match('/^ACCT_[A-Za-z0-9]+$/',$order['subaccount_code']) || !payment_keys_ready()) { flash('notice','This order cannot be paid until Paystack and recipient settings are complete.'); redirect('index.php?page=checkout'); }
if ($order['transaction_status']==='success') redirect('index.php?page=confirmation&id='.$orderId);
$saved = $order['raw_response'] ? json_decode($order['raw_response'],true) : null;
if (is_array($saved) && !empty($saved['authorization_url'])) redirect($saved['authorization_url']);
try {
    $grossKobo=payment_decimal_to_kobo($order['payment_total']);$baseKobo=payment_decimal_to_kobo($order['base_subtotal']);
    $payload=['email'=>$order['email'],'amount'=>(string)$grossKobo,'currency'=>'NGN','reference'=>$order['reference_code'],'callback_url'=>payment_callback_url(),'subaccount'=>$order['subaccount_code'],'transaction_charge'=>$grossKobo-$baseKobo,'bearer'=>'account','metadata'=>json_encode(['order_id'=>$orderId,'order_number'=>$order['order_number'],'recipient_net_kobo'=>$baseKobo],JSON_THROW_ON_ERROR)];
    $result=payment_api('POST','/transaction/initialize',$payload);$data=$result['data']??[];
    if(empty($data['authorization_url']))throw new RuntimeException('Paystack did not return a checkout link.');
    db()->prepare('UPDATE payment_transactions SET status="initialized",raw_response=? WHERE id=?')->execute([json_encode($data,JSON_THROW_ON_ERROR),$order['transaction_id']]);redirect($data['authorization_url']);
}catch(Throwable $error){flash('notice',$error->getMessage());redirect('index.php?page=checkout');}
