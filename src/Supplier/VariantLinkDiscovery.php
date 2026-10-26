<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\{ProductPartnerAssignment, ProductVariantSupplierRepository};

/** Cache-only, bounded discovery. This service never fetches a URL or writes sale/stock data. */
final class VariantLinkDiscovery
{
    private array $cache = [];
    public function __construct(private MeekroDB $db, private int $now) {}
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private static function issue(string $status, string $message): array { return compact('status', 'message'); }

    private function source(string $url): array
    {
        if (isset($this->cache[$url])) return $this->cache[$url];
        $original = $url; $expires = PHP_INT_MAX;
        for ($hop = 0; $hop < 4; $hop++) {
            try { HttpClient::url($url); } catch (InvalidArgumentException $e) { return $this->cache[$original] = self::issue('invalid_url', 'Adresa není bezpečným odkazem dodavatele.'); }
            $row = $this->db->queryFirstRow('SELECT expires_at,data_json FROM shop_supplier_price_cache WHERE url_hash=%s', HttpClient::cacheKey('product', $url));
            if ($row === null) return $this->cache[$original] = self::issue('missing_cache', 'Nabídka zatím není načtená. Párování počká na běžnou kontrolu cen.');
            $expires = min($expires, (int) $row['expires_at']);
            if ($expires <= $this->now) return $this->cache[$original] = self::issue('expired', 'Uložená nabídka je starší než povolená platnost. Je potřeba nová kontrola cen.');
            $data = json_decode($row['data_json'], true, 48, JSON_THROW_ON_ERROR);
            if (!empty($data['error'])) return $this->cache[$original] = self::issue('source_error', (string) $data['error']);
            if (!empty($data['redirect'])) {
                try { $url = HttpClient::redirect($url, $data['redirect']); }
                catch (InvalidArgumentException $e) { return $this->cache[$original] = self::issue('invalid_redirect', 'Přesměrování není bezpečným odkazem stejného dodavatele.'); }
                continue;
            }
            $observed = (int) ($data['observed_at'] ?? 0);
            if (!isset($data['document']) || $observed <= 0 || $observed > $this->now || $observed <= $this->now - 86400) return $this->cache[$original] = self::issue('expired', 'Chybí čerstvá nabídka s ověřitelným časem načtení.');
            return $this->cache[$original] = ['status' => 'loaded', 'source_url' => $original, 'resolved_url' => $url,
                'observed_at' => $observed, 'expires_at' => min($expires, $observed + 86400), 'document' => $data['document']];
        }
        return $this->cache[$original] = self::issue('invalid_redirect', 'Příliš mnoho přesměrování při hledání nabídky.');
    }

    private function plan(string $key, bool $lock = false): array
    {
        $repository = new ProductVariantSupplierRepository($this->db);
        $links = $repository->forProduct($key, $lock, true);
        $suffix = $lock ? ' FOR UPDATE' : '';
        $product = $this->db->queryFirstRow('SELECT name,language FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY (language=%s) DESC,language LIMIT 1' . $suffix, $key, 'cs');
        if ($product === null) throw new InvalidArgumentException('Produkt již neexistuje.');
        $sale = $this->db->queryFirstRow('SELECT mode FROM shop_product_sales WHERE product_key=%s' . $suffix, $key);
        $variants = array_column($this->db->query('SELECT variant_key,choices_json,enabled FROM shop_product_variants WHERE product_key=%s ORDER BY variant_key' . $suffix, $key), null, 'variant_key');
        $common = $this->db->queryFirstColumn('SELECT url FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id' . $suffix, $key);
        $partners = array_column($this->db->query('SELECT partner_key,website_url FROM shop_purchase_partners ORDER BY id' . $suffix), null, 'partner_key');
        $entries = []; $matchedDocuments = [];
        foreach ($links as $link) {
            if (!empty($link['removed'])) continue;
            $variant = $variants[$link['variant_key']] ?? null;
            $out = ['link_key' => $link['link_key'], 'variant_key' => $link['variant_key'], 'version' => $link['version'],
                'label' => $link['label'], 'old_url' => $link['url'], 'origin' => $link['origin'], 'choices' => $variant ? json_decode($variant['choices_json'], true) : [],
                'status' => 'inactive', 'message' => 'Párování je aktivní jen u nabízených variant asistovaného nákupu.'];
            if (($sale['mode'] ?? '') !== 'assisted' || !$variant || empty($variant['enabled'])) { $entries[] = $out; continue; }
            $host = ProductPartnerAssignment::host($link['url']);
            $partner = $partners[$link['partner_key'] ?? ''] ?? null;
            if (!$host || !$partner || ProductPartnerAssignment::host($partner['website_url']) !== $host) {
                $entries[] = array_replace($out, self::issue('missing_partner', 'Nejdřív přiřaď partnerský e-shop odpovídající tomuto odkazu.')); continue;
            }
            // A common model page can prove every variant; no need to fetch each deep link.
            $previousSource = $link['result']['source_url'] ?? $link['result']['last_verified']['source_url'] ?? null;
            $urls = array_values(array_unique(array_merge([$link['url']], $common,
                is_string($previousSource) ? [$previousSource] : [])));
            $matches = []; $failures = [];
            foreach ($urls as $url) {
                if (ProductPartnerAssignment::host($url) !== $host) continue;
                $source = $this->source($url);
                if ($source['status'] !== 'loaded') { $failures[] = $source; continue; }
                $memo = $variant['variant_key'] . ':' . $source['resolved_url'] . ':' . hash('sha256', self::json($source['document']));
                $match = $matchedDocuments[$memo] ??= VariantLinkMatcher::match($source['document'], $source['resolved_url'], $product['name'], $variant);
                if ($match['status'] !== 'exact') { $failures[] = $match; continue; }
                $matches[] = $match + array_intersect_key($source, array_flip(['source_url', 'resolved_url', 'observed_at', 'expires_at']));
            }
            if ($matches === []) {
                // Prefer a concrete mismatch over an absent secondary cache entry.
                $priority = ['missing_cache' => 2, 'expired' => 1];
                usort($failures, static fn(array $a, array $b): int => ($priority[$a['status']] ?? 0) <=> ($priority[$b['status']] ?? 0));
                $entries[] = array_replace($out, $failures[0] ?? self::issue('missing_cache', 'Chybí uložená nabídka.')); continue;
            }
            if (count(array_unique(array_column($matches, 'url'))) !== 1 || count(array_unique(array_map(static fn(array $m): string => (string) ($m['offer']['amount'] ?? ''), $matches))) !== 1) {
                $entries[] = array_replace($out, self::issue('conflict', 'Uložené nabídky se rozcházejí. Odkaz se automaticky nezměnil.')); continue;
            }
            usort($matches, static fn(array $a, array $b): int => $b['observed_at'] <=> $a['observed_at']);
            $out = array_replace($out, $matches[0]);
            if ($link['origin'] === 'manual' && $link['url'] !== $out['url']) {
                $out['status'] = 'manual_review'; $out['message'] = 'Nalezena přesná nabídka. Ručně zadaný odkaz zůstává zachovaný; návrh můžeš převzít do formuláře.';
            }
            $entries[] = $out;
        }
        // Preserve link identities and deletion tombstones instead of merging or resurrecting rows.
        $occupied = [];
        foreach ($links as $link) $occupied[$link['variant_key'] . ':' . $link['url']] = $link;
        foreach ($entries as &$entry) {
            if ($entry['status'] !== 'exact') continue;
            $pair = $entry['variant_key'] . ':' . $entry['url'];
            $other = $occupied[$pair] ?? null;
            if ($other && $other['link_key'] !== $entry['link_key']) {
                $entry['status'] = !empty($other['removed']) ? 'removed_target' : 'duplicate';
                $entry['message'] = !empty($other['removed']) ? 'Tento přesný odkaz byl dříve odebrán. Automatika jej neobnoví.' : 'Tato přesná nabídka už má vlastní odkaz u stejné varianty.';
            } else $occupied[$pair] = $entry;
        }
        unset($entry);
        $summary = [];
        foreach ($entries as $entry) $summary[$entry['status']] = ($summary[$entry['status']] ?? 0) + 1;
        return ['product_key' => $key, 'name' => $product['name'], 'language' => $product['language'], 'summary' => $summary, 'links' => $entries];
    }

    public function report(string $key = ''): array
    {
        if ($key !== '' && !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatný produkt.');
        if (!(new ProductVariantSupplierRepository($this->db))->installed()) return ['format' => 'simple-store.variant-link-plan.v1', 'items' => [], 'summary' => []];
        $keys = $key !== '' ? [$key] : $this->db->queryFirstColumn('SELECT DISTINCT l.product_key FROM shop_product_variant_supplier_links l INNER JOIN shop_product_revisions p ON p.product_key=l.product_key AND p.active_product_key IS NOT NULL WHERE l.removed=0 ORDER BY l.product_key');
        $items = []; $summary = [];
        foreach ($keys as $productKey) {
            $item = $this->plan($productKey); $items[] = $item;
            foreach ($item['summary'] as $status => $count) $summary[$status] = ($summary[$status] ?? 0) + $count;
        }
        return ['format' => 'simple-store.variant-link-plan.v1', 'matcher_version' => VariantLinkMatcher::VERSION, 'exported_at' => gmdate('c', $this->now), 'summary' => $summary, 'items' => $items];
    }

    public function process(string $key): array
    {
        $repository = new ProductVariantSupplierRepository($this->db);
        return $repository->transaction($key, function() use ($key, $repository): array {
            $plan = $this->plan($key, true); $existing = array_column($repository->forProduct($key, true), null, 'link_key'); $changed = 0;
            foreach ($plan['links'] as $entry) {
                // Own stock and retired combinations are outside automatic discovery.
                if ($entry['status'] === 'inactive') continue;
                $old = $existing[$entry['link_key']];
                $result = array_intersect_key($entry, array_flip(['status','message','url','source_url','resolved_url','observed_at','expires_at','offer','evidence','choices']));
                $result['matcher_version'] = VariantLinkMatcher::VERSION;
                $verification = $entry['status'] === 'exact' ? 'verified' : (in_array($entry['status'], ['conflict','ambiguous','manual_review'], true) ? 'conflict' : 'unverified');
                $values = ['verification' => $verification, 'result_json' => self::json($result)];
                if ($entry['status'] === 'exact') {
                    $values += ['url' => $entry['url'], 'url_hash' => hash('sha256', $entry['url']), 'checked_at' => $entry['observed_at']];
                    if ($entry['url'] !== $old['url']) $values['origin'] = 'discovered';
                    // Retain the initial common URL for audit and future cache lookup.
                    $result['original_url'] = $old['result']['original_url'] ?? $old['result']['last_verified']['original_url'] ?? $old['url'];
                    $values['result_json'] = self::json($result);
                } else {
                    $previous = $old['verification'] === 'verified' ? $old['result'] : ($old['result']['last_verified'] ?? null);
                    if ($previous) $result['last_verified'] = $previous;
                    $values['result_json'] = self::json($result);
                }
                $before = $old + ['result_json' => self::json($old['result'])];
                $different = false;
                foreach ($values as $field => $value) {
                    if ($field === 'url_hash') continue;
                    if (($before[$field] ?? null) !== $value) { $different = true; break; }
                }
                if (!$different) continue;
                $values['version'] = $old['version'] + 1;
                $this->db->update('shop_product_variant_supplier_links', $values, 'link_key=%s AND version=%i AND removed=0', $old['link_key'], $old['version']);
                if ($this->db->affectedRows() !== 1) throw new InvalidArgumentException('Odkaz se mezitím změnil. Párování se zopakuje s aktuálními údaji.');
                $changed++;
            }
            return ['changed' => $changed, 'summary' => $plan['summary']];
        });
    }

    public function batch(string $cursor = '', int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        if (!(new ProductVariantSupplierRepository($this->db))->installed()) return ['cursor' => '', 'more' => false, 'changed' => 0, 'processed' => 0];
        $keys = $this->db->queryFirstColumn('SELECT DISTINCT l.product_key FROM shop_product_variant_supplier_links l INNER JOIN shop_product_revisions p ON p.product_key=l.product_key AND p.active_product_key IS NOT NULL INNER JOIN shop_product_sales s ON s.product_key=l.product_key AND s.mode=%s WHERE l.removed=0 AND l.product_key>%s ORDER BY l.product_key LIMIT %i', 'assisted', $cursor, $limit + 1);
        $more = count($keys) > $limit; $changed = 0; $processed = 0;
        foreach (array_slice($keys, 0, $limit) as $key) { $changed += $this->process($key)['changed']; $cursor = $key; $processed++; }
        return ['cursor' => $more ? $cursor : '', 'more' => $more, 'changed' => $changed, 'processed' => $processed];
    }
}
