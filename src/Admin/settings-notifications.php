<?php
declare(strict_types=1);

use SimpleStore\Notification\NtfySettings;
use SimpleStore\Notification\NtfyQueue;
use SimpleStore\Notification\NtfyClient;

$form=[];$ntfyNotice='';$ntfyRows=[];$ntfy=null;
try {
    $store=new NtfySettings($db);
    if($method==='POST') {
        if($action==='ntfy-save') {
            $store->save($_POST);
            header('Location: '.\SimpleStore\Navigation\PanelUrl::pretty($adminUrl.'?section=settings&tab=notifications&saved=1'),true,303);exit;
        } elseif($action==='ntfy-test') {
            $saved=NtfySettings::portable($store->load());NtfySettings::validate($saved);
            if($saved['topic']==='')throw new InvalidArgumentException('Nejdřív ulož vlastní téma ntfy.');
            $result=(new NtfyClient())->send(['topic'=>$saved['topic'],'title'=>'Test oznámení obchodu','message'=>'Připojení funguje. Toto je pouze test, žádná objednávka nebyla vytvořena.','priority'=>$saved['priority'],'tags'=>['white_check_mark']],$saved['token']);
            if($result['state']!=='sent')throw new InvalidArgumentException($result['error']);
            $ntfyNotice='ntfy potvrdilo přijetí testovací zprávy. Zkontroluj oznámení v telefonu.';
        } elseif($action==='ntfy-retry') {
            if(($_POST['confirm_retry']??'')!=='1')throw new InvalidArgumentException('Nejdřív zkontroluj telefon a potvrď opakování.');
            $id=filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT);
            if(!$id || $id<1)throw new InvalidArgumentException('Neplatná objednávka.');
            $state=(new NtfyQueue($db))->dispatch($id,true);
            $ntfyNotice='Výsledek opakování: '.(['sent'=>'odesláno','failed'=>'chyba — podrobnosti v přehledu','uncertain'=>'nepotvrzeno — zkontroluj telefon','disabled'=>'oznámení jsou vypnutá','skipped'=>'vynecháno'][$state]??$state).'.';
        } else throw new InvalidArgumentException('Neplatná akce oznámení.');
    }
    $ntfy=NtfySettings::portable($store->load());
    $ntfyRows=(new NtfyQueue($db))->recent();
} catch(InvalidArgumentException $e) { $settingsError=$e->getMessage();http_response_code(422); }
catch(Throwable $e) { $settingsError='Nastavení nebo přehled ntfy nelze načíst. Zkontroluj databázi a šifrovací klíč instalace.';http_response_code(503); }
if($ntfy===null) {
    try {$ntfy=NtfySettings::portable((new NtfySettings($db))->load());}
    catch(Throwable $e) { /* Never replace unreadable saved credentials with blank defaults. */ }
}
