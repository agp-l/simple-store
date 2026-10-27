<?php
declare(strict_types=1);
namespace SimpleStore\Media;

use MeekroDB;

/** Owned files only. Historical order/content/merge references are deliberately retained. */
final class ProductMediaCleanup
{
    public function __construct(private MeekroDB $db, private string $root) {}

    public function protectedTokens(string $key, bool $lock = false): array
    {
        MediaPath::directory('product',$key);
        $needle='%'.$key.'%'; $suffix=$lock?' FOR UPDATE':''; $tokens=[];
        $queries=[
            ['SELECT image_path,details_json,description,summary FROM shop_product_revisions WHERE product_key<>%s AND (image_path LIKE %s OR details_json LIKE %s OR description LIKE %s OR summary LIKE %s)',[$key,$needle,$needle,$needle,$needle]],
            ['SELECT image_path FROM shop_product_variants WHERE product_key<>%s AND image_path LIKE %s',[$key,$needle]],
            ['SELECT body,summary FROM shop_content_revisions WHERE body LIKE %s OR summary LIKE %s',[$needle,$needle]],
            ['SELECT items_json FROM shop_orders WHERE items_json LIKE %s',[$needle]],
            ['SELECT items_json FROM shop_invoices WHERE items_json LIKE %s',[$needle]],
            ['SELECT before_json FROM shop_product_family_merges WHERE before_json LIKE %s',[$needle]],
        ];
        foreach ($queries as [$sql,$args]) foreach ($this->db->query($sql.$suffix,...$args) as $row) foreach ($row as $value) {
            if (!is_string($value)) continue;
            preg_match_all('/--([a-f0-9]{24})(?:-(?:card|thumb))?\.(?:webp|jpg|png)/',$value,$matches);
            foreach ($matches[1] as $token) $tokens[$token]=true;
        }
        return $tokens;
    }

    public static function token(string $path): string
    {
        if (!MediaPath::isManaged($path)) throw new \InvalidArgumentException('Neplatná fotografie k odstranění.');
        preg_match('/--([a-f0-9]{24})\./',$path,$match);
        return $match[1];
    }

    public function state(string $key): ?array
    {
        MediaPath::directory('product',$key);
        $row=$this->db->queryFirstRow('SELECT * FROM shop_product_media_cleanup WHERE product_key=%s',$key);
        if ($row===null) return null;
        $row['files']=json_decode($row['files_json'],true,32,JSON_THROW_ON_ERROR);
        $row['result']=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
        $row['counts']=['deleted'=>0,'retained'=>0,'pending'=>0];
        foreach ($row['files'] as $path) $row['counts'][$row['result'][$path]['status']??'pending']++;
        return $row;
    }

    /** Idempotent and bounded: a disk failure never rolls back the already removed catalog. */
    public function run(string $key, int $limit = 100): array
    {
        MediaPath::directory('product',$key);
        $this->db->startTransaction();
        try {
            $row=$this->db->queryFirstRow('SELECT * FROM shop_product_media_cleanup WHERE product_key=%s FOR UPDATE',$key);
            if ($row===null) throw new \InvalidArgumentException('Úklid produktu nebyl nalezen.');
            // A deliberate later re-import must never be damaged by an old cleanup job.
            if ($this->db->queryFirstField('SELECT product_key FROM shop_product_revisions WHERE product_key=%s LIMIT 1 FOR UPDATE',$key)!==null) throw new \InvalidArgumentException('Produkt byl znovu vytvořen. Starý úklid fotografií je zastaven.');
            $files=json_decode($row['files_json'],true,32,JSON_THROW_ON_ERROR);
            $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
            $protected=$this->protectedTokens($key,true); $processed=0; $deleted=[];
            foreach ($files as $path) {
                if (($result[$path]['status']??'pending')!=='pending') continue;
                if (++$processed>max(1,min(100,$limit))) break;
                if (isset($protected[self::token($path)])) { $result[$path]=['status'=>'retained']; continue; }
                try {
                    (new MediaLibrary($this->root))->deleteManaged('product',$key,$path,true);
                    $result[$path]=['status'=>'deleted']; $deleted[]=$path;
                } catch (\Throwable $e) { $result[$path]=['status'=>'pending','message'=>'Soubor se nepodařilo odstranit. Zkontroluj práva složky a zkus úklid znovu.']; }
            }
            if ($deleted!==[]) (new MediaCatalog($this->root))->update(static function(array &$state) use ($deleted): void {
                foreach ($deleted as $path) unset($state['files'][$path]);
            });
            $this->db->update('shop_product_media_cleanup',['result_json'=>json_encode($result,JSON_THROW_ON_ERROR)],'product_key=%s',$key);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
        return $this->state($key);
    }
}
