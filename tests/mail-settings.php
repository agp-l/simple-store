<?php
declare(strict_types=1);
class MeekroDB
{
    public ?array $settingsRow = null;
    public array $templates = [];
    private array $before = [];
    public function queryFirstField(string $sql, mixed ...$values): int { return 1; }
    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return str_contains($sql, 'FROM shop_mail_settings') ? $this->settingsRow : null;
    }
    public function query(string $sql, mixed ...$values): array
    {
        if (str_starts_with($sql, 'SELECT event_code')) return array_values($this->templates);
        if (str_starts_with($sql, 'INSERT INTO shop_mail_settings')) { $this->settingsRow = ['settings_json' => $values[1]]; return []; }
        if (str_starts_with($sql, 'INSERT INTO shop_mail_templates')) {
            $this->templates[$values[0]] = ['event_code' => $values[0], 'enabled' => $values[1], 'subject' => $values[2], 'message_text' => $values[3]];
            return [];
        }
        throw new RuntimeException('Unexpected query: ' . $sql);
    }
    public function startTransaction(): void { $this->before = [$this->settingsRow, $this->templates]; }
    public function commit(): void {}
    public function rollback(): void { [$this->settingsRow, $this->templates] = $this->before; }
}
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Accounting\{MailContent, MailSettingsRepository, MailPreview};
$db = new MeekroDB(); $store = new MailSettingsRepository($db);
$configuration = $store->load();
$input = ['from_email' => 'mail@example.test', 'from_name' => 'Obchod u lesa', 'reply_to' => 'podpora@example.test',
    'public_base_url' => 'https://example.test', 'automatic_enabled' => '1', 'templates' => [], 'copy' => MailContent::defaults()];
foreach ($configuration['templates'] as $code => $template) $input['templates'][$code] = $template + ['enabled' => '1'];
$input['templates']['order']['enabled'] = '1';
$input['templates']['invoice']['subject'] = 'Doklad {invoice_number} od {shop_name}';
$input['templates']['case_event']['message'] = 'Osobní text pro {customer_name}.';
$input['copy']['footer'] = 'Napište nám, pomůžeme vám.';
$input['copy']['button_order'] = 'Můj nákup';
$store->save($input);
$loaded = $store->load();
if ($loaded['templates']['invoice']['subject'] !== $input['templates']['invoice']['subject'] ||
    $loaded['templates']['case_event']['message'] !== $input['templates']['case_event']['message'] ||
    $loaded['settings']['copy']['footer'] !== $input['copy']['footer'] || !$loaded['templates']['order']['enabled']) {
    throw new RuntimeException('Custom mail settings did not survive save/load.');
}
foreach (MailContent::definitions() as $code => $definition) {
    if ($loaded['templates'][$code]['label'] !== $definition['label']) throw new RuntimeException('Template label missing.');
    $preview = MailPreview::compose($code, 'mixed', $loaded);
    if (!str_contains($preview['text'], $input['copy']['footer'])) throw new RuntimeException('Saved footer missing from ' . $code);
}
$draft = MailSettingsRepository::withDraft($loaded, ['templates' => $input['templates'], 'copy' => ['footer' => 'Jen náhled']]);
$draft['templates'] = MailSettingsRepository::validateTemplates($input['templates'], $loaded['templates']);
$before = [$db->settingsRow, $db->templates];
$preview = MailPreview::compose('invoice', 'mixed', $draft);
if (!str_contains($preview['html'], 'Jen náhled') || [$db->settingsRow, $db->templates] !== $before ||
    $draft['templates']['invoice']['label'] !== 'Faktura') throw new RuntimeException('Preview changed saved data or lost editor labels.');
foreach ([['templates' => array_replace($input['templates'], ['invoice' => ['subject' => "Injected\r\nBcc: x", 'message' => 'Text']])],
    ['copy' => array_replace($input['copy'], ['partner_prices' => 'Bez rozpisu'])]] as $bad) {
    try { $store->save(array_replace($input, $bad)); throw new RuntimeException('Unsafe configuration saved.'); }
    catch (InvalidArgumentException $expected) {}
    if ([$db->settingsRow, $db->templates] !== $before) throw new RuntimeException('Validation failure changed settings.');
}
$db->templates['order']['message_text'] = 'Děkujeme, {customer_name}. Objednávku jsme přijali. Níže najdete její souhrn a způsob úhrady.';
$db->templates['paid']['message_text'] = 'Vlastní potvrzení platby.';
$upgraded = $store->load();
if ($upgraded['templates']['order']['message'] !== MailSettingsRepository::EVENTS['order']['message'] ||
    $upgraded['templates']['paid']['message'] !== 'Vlastní potvrzení platby.') throw new RuntimeException('Default upgrade overwrote custom copy.');
// Older forms may lack newly introduced fields; their saved wording must survive.
$oldInput = $input; unset($oldInput['copy']);
foreach (array_keys(MailContent::EXTRA_TEMPLATES) as $code) unset($oldInput['templates'][$code]);
$store->save($oldInput);
if ($store->load()['templates']['invoice']['subject'] !== $input['templates']['invoice']['subject'] ||
    $store->load()['settings']['copy']['footer'] !== $input['copy']['footer']) throw new RuntimeException('Legacy form erased new copy.');
echo "Mail editor persistence, previews and legacy defaults OK\n";
