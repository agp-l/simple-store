<?php
declare(strict_types=1);
namespace SimpleStore\Notification;

use SimpleStore\Configuration\Installation;
use SimpleStore\Rendering\LocalDateTime;

final class NtfyMessage
{
    public static function order(array $order, array $settings, ?string $baseUrl=null): array
    {
        $money=static fn(int $n):string=>number_format($n,0,',',' ').' Kč';
        $shipping=$order['shipping_ordered']??$order['shipping']??[];
        $payment=['bank_transfer'=>'Bankovní převod','comgate'=>'Comgate','gopay'=>'GoPay','btcpay'=>'Bitcoin'];
        $lines=['Kdy: '.LocalDateTime::czech($order['created_at']??null).' (Praha)',
            'Kdo: '.self::line($shipping['recipient']??$shipping['name']??'Zákazník'),
            'Celkem: '.$money((int)$order['total_czk']),
            'Doprava: '.$money((int)($order['shipping_czk']??0)).' · '.self::line($shipping['label']??''),
            'Platba: '.self::line($payment[$order['payment_method']??'']??($order['payment_method']??'')).' · '.(($order['payment_status']??'')==='paid'?'uhrazeno':'zatím nepotvrzená')];
        if ($settings['include_contact']) {
            $lines[]='E-mail: '.self::line($order['customer_email']??'');
            if (!empty($shipping['phone'])) $lines[]='Telefon: '.self::line($shipping['phone']);
        }
        $lines[]='';$lines[]='Zboží:';
        foreach($order['items']??[] as $item) {
            $options=[];
            foreach($item['options']??[] as $name=>$value) if(is_scalar($value))$options[]=self::line((string)$name).': '.self::line((string)$value);
            $lines[]=(int)$item['quantity'].'× '.self::line($item['name']).($options!==[]?' ('.implode(', ',$options).')':'').
                ' — '.$money((int)$item['quantity']*(int)$item['unit_price_czk']);
        }
        $message=implode("\n",$lines);
        if(strlen($message)>3500)$message=self::clip($message,3400)."\n… Celý seznam najdeš v detailu objednávky.";
        $payload=['topic'=>$settings['topic'],'title'=>'Nová objednávka '.self::line($order['order_number']),
            'message'=>$message,'priority'=>$settings['priority'],'tags'=>['shopping_cart']];
        $baseUrl??=Installation::current()->baseUrl();
        if(str_starts_with($baseUrl,'https://'))$payload['click']=rtrim($baseUrl,'/').'/admin/orders/?id='.(int)$order['id'];
        return $payload;
    }
    private static function line(mixed $value):string
    {
        return self::clip(trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',(string)$value)??''),300);
    }
    private static function clip(string $value,int $limit):string
    {
        $value=substr($value,0,$limit);
        while($value!=='' && preg_match('//u',$value)!==1)$value=substr($value,0,-1);
        return $value;
    }
}
