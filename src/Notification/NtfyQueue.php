<?php
declare(strict_types=1);
namespace SimpleStore\Notification;

use MeekroDB;
use Throwable;
use SimpleStore\Checkout\OrderRepository;

/** Only references are queued; customer data is read at dispatch, never stored twice. */
final class NtfyQueue
{
    private \Closure $transport;
    public function __construct(private MeekroDB $db, ?callable $transport=null)
    {
        $this->transport=$transport===null ? \Closure::fromCallable([new NtfyClient(),'send']) : \Closure::fromCallable($transport);
    }
    /** Called ONLY after a new order commits. Notification failures cannot undo checkout. */
    public static function afterOrder(MeekroDB $db,array $order):void
    {
        try { $queue=new self($db); if($queue->enqueue($order))$queue->dispatch((int)$order['id']); }
        catch(Throwable $e) { error_log('ntfy: notification unavailable; saved order is unaffected.'); }
    }
    public function enqueue(array $order):bool
    {
        $settings=(new NtfySettings($this->db))->load();
        if(!$settings['enabled'])return false;
        NtfySettings::validate(NtfySettings::portable($settings));
        $this->db->query('INSERT IGNORE INTO shop_ntfy_outbox (order_id,order_number,topic_hash) VALUES (%i,%s,%s)',
            (int)$order['id'],$order['order_number'],hash('sha256',$settings['topic']));
        return $this->db->affectedRows()===1;
    }
    public function dispatch(int $id,bool $manual=false):string
    {
        $store=new NtfySettings($this->db);$s=$store->load();
        if(!$s['enabled'])return 'disabled';
        $s=NtfySettings::portable($s);NtfySettings::validate($s);
        $this->recover();
        // An atomic claim prevents two workers sending the same order. No network inside a transaction.
        $allowed=$manual ? "state IN ('pending','failed','uncertain')" : "state IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())";
        $this->db->query("UPDATE shop_ntfy_outbox SET state='sending',attempts=attempts+1,attempted_at=NOW(),last_error='' WHERE order_id=%i AND ".$allowed,$id);
        if($this->db->affectedRows()!==1)return 'skipped';
        $row=$this->db->queryFirstRow('SELECT * FROM shop_ntfy_outbox WHERE order_id=%i',$id);
        try {
            $order=(new OrderRepository($this->db))->findById($id);
            if($order===null || $order['order_number']!==$row['order_number'] || !hash_equals($row['topic_hash'],hash('sha256',$s['topic']))) {
                $result=['state'=>'skipped','error'=>'Objednávka již neexistuje nebo bylo změněno téma. Zpráva nebyla odeslána.'];
            } else {
                $result=($this->transport)(NtfyMessage::order($order,$s),$s['token']);
                if(!in_array($result['state']??null,['sent','failed','uncertain'],true))throw new \RuntimeException('Invalid result');
            }
        } catch(Throwable $e) { $result=['state'=>'uncertain','error'=>'Odeslání nelze potvrdit. Před opakováním zkontroluj telefon.']; }
        $this->db->query("UPDATE shop_ntfy_outbox SET state=%s,last_error=%s,next_attempt_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),sent_at=IF(%s='sent',NOW(),NULL) WHERE order_id=%i AND state='sending'",
            $result['state'],$result['error'],$result['state'],$id);
        return $result['state'];
    }
    public function dispatchDue(int $limit=3):void
    {
        $store=new NtfySettings($this->db);
        if(!$store->load()['enabled'])return;
        $this->recover();
        foreach($this->db->query("SELECT order_id FROM shop_ntfy_outbox WHERE state IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY created_at,order_id LIMIT %i",max(1,min(20,$limit))) as $row)$this->dispatch((int)$row['order_id']);
    }
    public function recent():array
    {
        if(!(new NtfySettings($this->db))->installed())return [];
        $this->recover();
        return $this->db->query('SELECT * FROM shop_ntfy_outbox ORDER BY created_at DESC,order_id DESC LIMIT 30');
    }
    private function recover():void
    {
        $this->db->query("UPDATE shop_ntfy_outbox SET state='uncertain',last_error='Přerušené odesílání. Před opakováním zkontroluj telefon.' WHERE state='sending' AND attempted_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
    }
}
