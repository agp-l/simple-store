<?php
declare(strict_types=1);

use SimpleStore\Admin\SettingsArchive as Archive;
use SimpleStore\Accounting\MailCredential;

// Reached only after admin.php authentication and POST CSRF checks.
$form = []; $archivePlan = null; $archiveError = ''; $archiveNotice = '';
$archivePending = null;
$example = require __DIR__.'/../../config/checkout.example.php';
$local = __DIR__.'/../../config/checkout.php';
$fallback = \SimpleStore\Checkout\CheckoutSettingsRepository::withDefaults(is_file($local) ? require $local : $example, $example);
$mail = (new \SimpleStore\Accounting\MailSettingsRepository($db))->load();
$defaults = [
    'checkout'=>[1=>['settings_json'=>$fallback]],
    'google'=>[1=>['settings_json'=>Archive::portable(\SimpleStore\Customer\GoogleLoginSettings::DEFAULTS)]],
    'mail'=>[1=>['settings_json'=>Archive::portable($mail['settings'])]],
    'business'=>[1=>['settings_json'=>(new \SimpleStore\Accounting\TaxEvidenceRepository($db))->settings()]],
    'mail_templates'=>[], 'site_texts'=>[],
    'product_import'=>[1=>['prompt_text'=>\SimpleStore\Product\ProductImportPromptRepository::defaultText()]],
    'supplier_prices'=>[1=>['paused'=>0,'weekly_enabled'=>0,'cron_hash'=>'','options_json'=>\SimpleStore\Supplier\PricingPolicy::DEFAULTS]],
];
foreach ($mail['templates'] as $code=>$entry) $defaults['mail_templates'][$code]=['enabled'=>(int)$entry['enabled'],'subject'=>$entry['subject'],'message_text'=>$entry['message']];
foreach ($site['languages'] as $language) $defaults['site_texts'][$language]=['copy_json'=>\SimpleStore\Content\SiteCopyRepository::DEFAULTS];
$archive = new Archive($db, $defaults);
$envelope = static function(array $data) use ($site, $db): array {
    $installation=\SimpleStore\Configuration\Installation::current();
    $reference=['installation'=>['public_base_url'=>$installation->baseUrl(),'previous_base_urls'=>$installation->previousBaseUrls()], 'site'=>$site];
    foreach (['menus','product-card-effects'] as $name) $reference[$name]=require __DIR__.'/../../config/'.$name.'.php';
    foreach (['shop_purchase_partners','shop_partner_delivery_costs','shop_document_series','shop_navigation_menus','shop_catalog_categories'] as $table) {
        if ((int)$db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)>0) $reference[$table]=$db->query('SELECT * FROM '.$table);
    }
    return ['format'=>Archive::FORMAT,'version'=>1,'exported_at'=>gmdate('c'),'data'=>$data,'reference_only'=>$reference];
};
$download = static function(array $value, string $prefix): void {
    header('Cache-Control: private, no-store'); header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$prefix.'-'.gmdate('Ymd-His').'.json"');
    echo Archive::json($value); exit;
};
try {
    $pending=$_SESSION['settings_archive_pending']??null;
    if (is_array($pending) && ($pending['expires']??0)>=time()) {
        try {
            $archivePending=json_decode(MailCredential::decrypt($pending['encrypted']),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($archivePending) || !is_string($archivePending['token']??null) || !is_array($archivePending['before']['data']??null) || !is_array($archivePending['incoming']??null) || !is_bool($archivePending['overwrite']??null) || !is_string($archivePending['version']??null)) throw new RuntimeException('Invalid staged preview');
        } catch (Throwable $error) {
            unset($_SESSION['settings_archive_pending']); $archivePending=null;
            $archiveError='Uložený náhled nelze přečíst a byl zrušen. Nastavení se nezměnilo; soubor můžeš nahrát znovu nebo stáhnout novou zálohu.';
        }
    } else unset($_SESSION['settings_archive_pending']);
    if ($method==='POST') {
        if ($action==='settings-export-download') {
            $db->startTransaction();
            try { $snapshot=$envelope($archive->snapshot()); $db->commit(); }
            catch (Throwable $e) { $db->rollback(); throw $e; }
            $download($snapshot,'nastaveni');
        } elseif ($action==='settings-import-preview') {
            unset($_SESSION['settings_archive_pending']); $archivePending=null;
            $upload=$_FILES['settings_file']??null;
            if (!is_array($upload) || ($upload['error']??-1)!==UPLOAD_ERR_OK || !is_string($upload['tmp_name']??null) || !is_uploaded_file($upload['tmp_name']) || ($upload['size']??0)>Archive::LIMIT) throw new InvalidArgumentException('Vyber JSON soubor do 8 MiB.');
            $json=file_get_contents($upload['tmp_name']);
            if ($json===false) throw new InvalidArgumentException('Soubor nelze přečíst.');
            $incoming=Archive::decode($json);
            $overwrite=($_POST['mode']??'fill')==='overwrite';
            $db->startTransaction();
            try { $before=$envelope($archive->snapshot()); $db->commit(); }
            catch (Throwable $e) { $db->rollback(); throw $e; }
            $archivePlan=$archive->plan($before['data'],$incoming,$overwrite);
            $archivePending=['token'=>bin2hex(random_bytes(24)),'incoming'=>$incoming,'overwrite'=>$overwrite,'version'=>$archivePlan['version'],'before'=>$before];
            $_SESSION['settings_archive_pending']=['expires'=>time()+1800,'downloaded'=>false,'encrypted'=>MailCredential::encrypt(Archive::json($archivePending))];
        } elseif (in_array($action,['settings-import-backup','settings-import-apply'],true)) {
            if ($archivePending===null || !is_string($_POST['preview_token']??null) || !hash_equals($archivePending['token'],$_POST['preview_token'])) throw new InvalidArgumentException('Náhled vypršel. Nahraj soubor znovu.');
            if ($action==='settings-import-backup') {
                $_SESSION['settings_archive_pending']['downloaded']=true;
                $download($archivePending['before'],'nastaveni-pred-importem');
            }
            if (empty($_SESSION['settings_archive_pending']['downloaded']) || ($_POST['confirm']??'')!=='1') throw new InvalidArgumentException('Nejdřív stáhni zálohu před importem a potvrď změny.');
            $count=$archive->apply($archivePending['incoming'],$archivePending['overwrite'],$archivePending['version']);
            unset($_SESSION['settings_archive_pending']); $archivePending=null;
            $archiveNotice='Import dokončen. Změněných záznamů: '.$count.'. Vynechaná a prázdná pole zůstala zachovaná.';
        } elseif ($action==='settings-import-cancel') {
            unset($_SESSION['settings_archive_pending']); $archivePending=null;
        } else throw new InvalidArgumentException('Neplatná akce exportu.');
    }
    if ($archivePending!==null && $archivePlan===null) $archivePlan=$archive->plan($archivePending['before']['data'],$archivePending['incoming'],$archivePending['overwrite']);
} catch (InvalidArgumentException $e) { $archiveError=$e->getMessage(); http_response_code(422); }
catch (Throwable $e) { $archiveError='Operaci se nepodařilo dokončit. Import nebyl uložen. Zkontroluj databázi a šifrovací klíč instalace.'; http_response_code(503); }
