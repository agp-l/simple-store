<?php
declare(strict_types=1);

class MeekroDB
{
    public array $json = [];
    public function queryFirstField(string $sql, mixed ...$values): mixed
    {
        if (str_contains($sql, 'information_schema')) return str_contains($sql, ' IN ') ? 2 : 1;
        if (str_contains($sql, 'shop_google_login_settings')) return $this->json['google'] ?? null;
        return null;
    }
    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return ['settings_json' => $this->json['mail'] ?? '{}'];
    }
    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'shop_google_login_settings')) $this->json['google'] = $values[0];
        return [];
    }
}
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Configuration\Installation;
use SimpleStore\Customer\GoogleLoginSettings;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Product\ProductText;
use SimpleStore\Seo\PublicSite;

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/installation.json'), true, 16, JSON_THROW_ON_ERROR);
foreach ($cases['valid'] as [$input, $expected]) same($expected, Installation::normalize($input), 'URL normalization');
foreach ($cases['invalid'] as $input) {
    try { new Installation($input); throw new RuntimeException('Invalid URL accepted: ' . $input); }
    catch (InvalidArgumentException $expected) {}
}
foreach (['https://dobrodruzi.cz', 'https://obchod.example.test', 'https://example.test/eshop', 'https://example.test/a/b'] as $base) {
    $installation = new Installation($base, ['https://eshop.dobrodruzi.cz', 'https://old.example.test/shop']);
    $path = rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/';
    same($path, $installation->basePath(), 'Installation prefix');
    $target = $base . '/cs/produkt/test?v_id=variant_a&color=blue#fotky';
    same($target, $installation->relocate('https://eshop.dobrodruzi.cz:443/cs/produkt/test?v_id=variant_a&color=blue#fotky'), 'Old root link');
    same($target, $installation->relocate('https://old.example.test/shop/cs/produkt/test?v_id=variant_a&color=blue#fotky'), 'Old directory link');
    same($target, $installation->relocate($target), 'Relocation is idempotent');
    foreach (['https://old.example.test/shopping/cs', 'https://www.4camping.cz/p/test/#modra', 'https://eshop.dobrodruzi.cz.evil.test/cs', 'https://evil.test@eshop.dobrodruzi.cz/cs', 'https://dobrodruzi.cz/cs', '//eshop.dobrodruzi.cz/cs'] as $external) {
        same($external, $installation->relocate($external), 'Other websites unchanged');
    }
    $checkout = $installation->checkout(['terms_url' => '/cs/obchodni-podminky', 'comgate' => ['secret' => 'preserve', 'return_base_url' => 'https://obsolete.test']]);
    same($path . 'cs/obchodni-podminky', $checkout['terms_url'], 'Stored terms path');
    same('preserve', $checkout['comgate']['secret'], 'Gateway secret preserved');
    foreach (['comgate', 'gopay', 'btcpay'] as $gateway) same($base, $checkout[$gateway]['return_base_url'], 'Gateway origin');
    same($base . '/account/?google=callback', $installation->url('account/?google=callback'), 'OAuth callback');
    $db = new MeekroDB();
    $db->json['google'] = json_encode(['enabled' => true, 'client_id' => '123.apps.googleusercontent.com', 'client_secret_encrypted' => 'preserved-secret', 'redirect_uri' => 'https://obsolete.test/account/?google=callback']);
    $google = new GoogleLoginSettings($db, $installation);
    $google->save(['enabled' => '1', 'client_id' => '123.apps.googleusercontent.com', 'client_secret' => '', 'redirect_uri' => 'https://evil.test']);
    same($installation->url('account/?google=callback'), $google->load()['redirect_uri'], 'Stored/posted Google URL ignored');
    same('preserved-secret', $google->load()['client_secret_encrypted'], 'Google credential preserved');
    $db->json['mail'] = json_encode(['public_base_url' => 'https://obsolete.test', 'from_email' => 'mail@example.test']);
    $mail = (new MailSettingsRepository($db, $installation))->load();
    same($base, $mail['settings']['public_base_url'], 'Legacy mail URL ignored');
    same('mail@example.test', $mail['settings']['from_email'], 'Sender unchanged');
    $html = ProductText::inline('[Článek](https://eshop.dobrodruzi.cz/cs/blog/test) <script>alert(1)</script>', $installation);
    if (!str_contains($html, 'href="' . $base . '/cs/blog/test"') || str_contains($html, '<script>')) throw new RuntimeException('Safe content relocation failed.');
    same($base, PublicSite::base($base, $path), 'Canonical SEO base');
}
$sameOrigin = new Installation('https://example.test/shop', ['https://example.test']);
same('https://example.test/shop/cs', $sameOrigin->relocate('https://example.test/cs'), 'Root to directory');
same('https://example.test/shop/cs', $sameOrigin->relocate('https://example.test/shop/cs'), 'No doubled directory');
$file = tempnam(sys_get_temp_dir(), 'installation-');
try {
    file_put_contents($file, json_encode(['public_base_url' => 'https://new.test/shop', 'previous_base_urls' => ['https://old.test']]));
    putenv('SIMPLE_STORE_INSTALLATION_FILE=' . $file);
    $_SERVER['HTTP_HOST'] = 'attacker.test';
    same('https://new.test/shop', Installation::current()->baseUrl(), 'Local file is authoritative');
    foreach (['{}', '{broken', '{"public_base_url":"https://new.test","extra":1}', '{"public_base_url":"https://new.test","previous_base_urls":{}}', str_repeat('x', 16385)] as $invalid) {
        file_put_contents($file, $invalid);
        try { Installation::fromFile($file); throw new LogicException('Invalid JSON accepted'); }
        catch (RuntimeException $expected) {}
    }
} finally { unlink($file); putenv('SIMPLE_STORE_INSTALLATION_FILE'); }
echo "Installation addresses, repositories, content relocation and config validation OK\n";
