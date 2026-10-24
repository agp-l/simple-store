<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Admin\AccountManagementRepository;
$input=['username'=>'eva.nova','email'=>' EVA@EXAMPLE.TEST ','display_name'=>' Eva Nová ','phone'=>'+420 777','role'=>'admin','is_active'=>'1'];
$actual=AccountManagementRepository::profile($input);
if ($actual['email']!=='eva@example.test' || $actual['display_name']!=='Eva Nová' || $actual['is_active']!==1) throw new RuntimeException('Profile normalization failed.');
foreach ([['email'=>'bad'],['username'=>'x'],['display_name'=>"Te\0st"],['role'=>'owner'],['is_active'=>'yes'],['email'=>[]],['phone'=>str_repeat('a',41)]] as $invalid) {
    try { AccountManagementRepository::profile(array_replace($input,$invalid)); throw new RuntimeException('Malformed profile accepted.'); }
    catch (InvalidArgumentException) {}
}
foreach (['short',str_repeat('a',73),"1234567890\0password"] as $password) {
    try { AccountManagementRepository::password($password); throw new RuntimeException('Malformed password accepted.'); }
    catch (InvalidArgumentException) {}
}
AccountManagementRepository::password('Manual-password-123');
echo "Account validation tests passed.\n";
