<?php
declare(strict_types=1);
$screen='settings'; $settingsError=''; $settingsTab='accounts'; $form=[];
$googleSettingsStore=new \SimpleStore\Customer\GoogleLoginSettings($db);
$googleSettingsReady=$googleSettingsStore->installed();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if($action!=='save-google-login') throw new InvalidArgumentException('Neplatná akce.');
        $googleSettingsStore->save($_POST);
        header('Location: '.\SimpleStore\Navigation\PanelUrl::pretty($adminUrl.'?section=settings&tab=accounts&saved=1'),true,303); exit;
    } catch(InvalidArgumentException $e) { $settingsError=$e->getMessage(); }
    catch(Throwable $e) { $settingsError='Nastavení Google se nepodařilo uložit. Zkontroluj dostupnost databáze a šifrovacího klíče.'; }
}
$googleSettings=$googleSettingsStore->load();

$googleClientSecret = '';
if ($googleSettingsReady && $googleSettings['client_secret_encrypted'] !== '') {
    try {
        $googleClientSecret = \SimpleStore\Accounting\MailCredential::decrypt($googleSettings['client_secret_encrypted']);
    } catch (Throwable $e) {
        $settingsError = 'Uložený Client secret nelze přečíst. Vlož jej znovu z Google Cloud nebo obnov šifrovací klíč instalace.';
    }
}
