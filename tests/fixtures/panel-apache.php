<?php
require '/app/src/bootstrap.php';
use SimpleStore\Auth\RoleAuth;
use SimpleStore\Navigation\PanelUrl;
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$area = basename($_SERVER['SCRIPT_FILENAME']) === 'admin.php' ? 'admin' : 'account';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
PanelUrl::dispatch($area, $base);
final class ApachePanelAuth extends RoleAuth
{
    public function __construct(private string $area, string $base) {
        parent::__construct($base, 'simple_store_' . ($area === 'admin' ? 'admin' : 'customer'), $area === 'admin' ? 'admin' : 'customer');
    }
    protected function byName(string $name): ?array { return $name === $this->area . '@example.test' ? $this->byId(1) : null; }
    protected function byId(int $id): ?array {
        return $id === 1 ? ['id'=>1, 'password_hash'=>trim(file_get_contents('/var/www/html/password-hash.txt'))] : null;
    }
}
$auth = new ApachePanelAuth($area, $base);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validToken($_POST['csrf'] ?? null)) { http_response_code(403); exit('csrf'); }
    if (($_POST['action'] ?? '') === 'login') {
        if (!$auth->signIn((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) { http_response_code(403); exit('login'); }
        header('Location: ' . PanelUrl::path($base,$area),true,303); exit;
    }
    if (!$auth->signedIn()) { http_response_code(403); exit('login required'); }
}
header('Content-Type: application/json');
echo json_encode(['area'=>$area,'base'=>$base,'query'=>$_GET,'method'=>$_SERVER['REQUEST_METHOD'],
    'csrf'=>$auth->token(),'signed'=>$auth->signedIn(),'form'=>$_POST,
    'upload'=>isset($_FILES['upload']) ? file_get_contents($_FILES['upload']['tmp_name']) : null], JSON_THROW_ON_ERROR);
