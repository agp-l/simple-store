<?php
declare(strict_types=1);
namespace SimpleStore\Customer;
use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** A Google sub belongs to exactly one customer. Matching email alone never links an account. */
final class GoogleIdentityRepository
{
    public function __construct(private MeekroDB $db) {}
    public function linked(int $userId): ?array
    {
        return $this->db->queryFirstRow('SELECT google_email, google_sub FROM shop_customer_google WHERE user_id=%i',$userId);
    }
    public function resolve(array $claims, ?int $targetId, bool $allowRegistration, string $binding = ''): int
    {
        $sub=$claims['sub']; $email=strtolower($claims['email']);
        $this->db->startTransaction();
        try {
            if($targetId!==null) {
                $target=$this->db->queryFirstRow('SELECT * FROM shop_users WHERE id=%i AND role=%s AND is_active=1 FOR UPDATE',$targetId,'customer');
                if($target===null || !hash_equals($binding,GoogleOAuth::binding($target))) throw new InvalidArgumentException('Účet se mezitím změnil. Přihlas se znovu.');
            }
            $identity=$this->db->queryFirstRow('SELECT g.user_id,u.role,u.is_active FROM shop_customer_google g JOIN shop_users u ON u.id=g.user_id WHERE g.google_sub=%s FOR UPDATE',$sub);
            if($identity!==null) {
                if($identity['role']!=='customer' || !(bool)$identity['is_active'] || ($targetId!==null && $targetId!==(int)$identity['user_id'])) throw new InvalidArgumentException('Tento Google účet nelze připojit.');
                $id=(int)$identity['user_id'];
            } elseif($targetId!==null) {
                $user=$this->db->queryFirstRow('SELECT id FROM shop_users WHERE id=%i AND role=%s AND is_active=1 FOR UPDATE',$targetId,'customer');
                if($user===null || $this->linked($targetId)!==null) throw new InvalidArgumentException('Účet už má připojený jiný Google účet.');
                $id=$targetId;
                $this->db->insert('shop_customer_google',['user_id'=>$id,'google_sub'=>$sub,'google_email'=>$email]);
                $this->db->query('UPDATE shop_users SET auth_version=auth_version+1 WHERE id=%i',$id);
            } else {
                if(!$allowRegistration) throw new InvalidArgumentException('Registrace nových účtů je vypnutá.');
                if($this->db->queryFirstRow('SELECT id FROM shop_users WHERE email=%s FOR UPDATE',$email)!==null) throw new InvalidArgumentException('Účet s touto adresou už existuje. Přihlas se heslem a Google připoj v Nastavení účtu. Pokud heslo neznáš, použij obnovu hesla.');
                $name=is_string($claims['name']??null)?trim($claims['name']):'';
                $name=mb_substr(preg_replace('/\p{C}/u','',$name)??'',0,120);
                $this->db->insert('shop_users',['username'=>'customer_'.bin2hex(random_bytes(12)),'email'=>$email,'display_name'=>$name!==''?$name:'Zákazník','phone'=>'','password_hash'=>'','role'=>'customer','is_active'=>1]);
                $id=(int)$this->db->insertId();
                $this->db->insert('shop_customer_google',['user_id'=>$id,'google_sub'=>$sub,'google_email'=>$email]);
            }
            $this->db->commit(); return $id;
        } catch(Throwable $error) { $this->db->rollback(); throw $error; }
    }
    public function unlink(int $id,string $password): void
    {
        $this->db->startTransaction();
        try {
            $user=$this->db->queryFirstRow('SELECT * FROM shop_users WHERE id=%i AND role=%s AND is_active=1 FOR UPDATE',$id,'customer');
            if($user===null || $user['password_hash']==='' || !password_verify($password,$user['password_hash'])) throw new InvalidArgumentException('Pro odpojení Google nejprve nastav heslo a potvrď jej.');
            $this->db->query('DELETE FROM shop_customer_google WHERE user_id=%i',$id);
            $this->db->query('UPDATE shop_users SET auth_version=auth_version+1 WHERE id=%i',$id);
            $this->db->commit();
        } catch(Throwable $error) { $this->db->rollback(); throw $error; }
    }
    /** Only called after fresh Google reauthentication bound to this customer session. */
    public function firstPassword(int $id,string $password): void
    {
        if(preg_match('/^.{10,}$/usD',$password)!==1 || strlen($password)>72) throw new InvalidArgumentException('Heslo musí mít alespoň 10 znaků a nejvýše 72 bajtů.');
        $this->db->startTransaction();
        try {
            $user=$this->db->queryFirstRow('SELECT * FROM shop_users WHERE id=%i AND role=%s AND is_active=1 FOR UPDATE',$id,'customer');
            if($user===null || $user['password_hash']!=='' || $this->linked($id)===null) throw new InvalidArgumentException('Účet se změnil. Obnov stránku.');
            $this->db->query('UPDATE shop_users SET password_hash=%s,password_changed_at=UTC_TIMESTAMP(),auth_version=auth_version+1 WHERE id=%i',password_hash($password,PASSWORD_DEFAULT),$id);
            $this->db->commit();
        } catch(Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
