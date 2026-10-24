<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;

/** Editable wording shared by HTML, plain text, previews and every customer mail. */
final class MailContent
{
    public const EXTRA_TEMPLATES = [
        'late_paid' => ['label' => 'Platba po stornu', 'subject' => 'Platba za zrušenou objednávku {order_number}',
            'message' => "Dobrý den,\n\nplatba k objednávce {order_number} dorazila až po jejím zrušení. Objednávka zůstává zrušená. Platbu prověříme a ozveme se vám s dalším postupem."],
        'invoice' => ['label' => 'Faktura', 'subject' => 'Faktura {invoice_number}',
            'message' => "Dobrý den,\n\nposíláme vám fakturu k objednávce {order_number}. Platbu už máme potvrzenou, nic dalšího neplaťte."],
        'case_receipt' => ['label' => 'Přijetí reklamace', 'subject' => 'Přijali jsme reklamaci {case_number}',
            'message' => "Dobrý den,\n\nvaši reklamaci jsme přijali. Níže najdete potvrzení a přehled zaslaných údajů. Ozveme se vám s dalším postupem."],
        'withdrawal_receipt' => ['label' => 'Přijetí odstoupení od smlouvy', 'subject' => 'Potvrzení odstoupení od smlouvy {case_number}',
            'message' => "Dobrý den,\n\npotvrzujeme přijetí vašeho odstoupení od smlouvy. Podrobnosti jsme shrnuli níže. Pokud bude potřeba něco doplnit, ozveme se vám."],
        'case_event' => ['label' => 'Odpověď k reklamaci nebo vrácení', 'subject' => 'Nová zpráva k případu {case_number}',
            'message' => "Dobrý den,\n\nmáme pro vás zprávu k případu {case_number}."],
        'password_reset' => ['label' => 'Obnova hesla zákazníka', 'subject' => 'Nastavení nového hesla · {shop_name}',
            'message' => "Dobrý den,\n\nobdrželi jsme žádost o nové heslo k vašemu účtu. Nastavíte si ho pomocí tlačítka níže."],
        'admin_password_reset' => ['label' => 'Obnova hesla správce', 'subject' => 'Nové heslo do administrace · {shop_name}',
            'message' => "Dobrý den,\n\nobdrželi jsme žádost o změnu hesla do administrace. Nové heslo si nastavíte pomocí tlačítka níže."],
        'test' => ['label' => 'Test doručení', 'subject' => 'Zkouška e-mailů · {shop_name}',
            'message' => "Dobrý den,\n\ntohle je zkušební zpráva z vašeho obchodu. Pokud ji vidíte ve schránce, odesílání funguje. Zkontrolujte ještě jméno odesílatele a adresu pro odpovědi."],
    ];

    public const GROUPS = [
        'Podpis a vzhled' => [
            'brand_line' => ['Text pod názvem obchodu', 'Vybavení pro vaše cesty'],
            'signoff' => ['Podpis', "Děkujeme,\n{shop_name}"],
            'footer' => ['Patička', 'Potřebujete s něčím poradit? Stačí odpovědět na tento e-mail.'],
        ],
        'Objednávka a doručení' => [
            'order_heading' => ['Hlavní nadpis', 'Objednávka {order_number}'],
            'order' => ['Objednávka – popisek', 'Objednávka'],
            'status' => ['Stav – popisek', 'Stav'],
            'status_order' => ['Přijetí objednávky – stav', 'Objednávka přijata'],
            'status_paid' => ['Platba – stav', 'Platba potvrzena'],
            'status_processing' => ['Příprava – stav', 'Připravujeme objednávku'],
            'status_ready_to_ship' => ['Připraveno – stav', 'Připraveno k odeslání'],
            'status_shipped' => ['Odesláno – stav', 'Zásilka je na cestě'],
            'status_tracking' => ['Sledování – stav', 'Sledování zásilky'],
            'status_completed' => ['Dokončeno – stav', 'Objednávka dokončena'],
            'status_cancelled' => ['Storno – stav', 'Objednávka zrušena'],
            'status_late_paid' => ['Platba po stornu – stav', 'Platba po zrušení objednávky'],
            'items' => ['Nadpis položek', 'Objednané zboží'],
            'goods' => ['Cena zboží – popisek', 'Zboží'],
            'shipping' => ['Cena dopravy – popisek', 'Doprava'],
            'total' => ['Celková částka – popisek', 'Celkem'],
            'delivery' => ['Nadpis adresy', 'Doručení'],
            'pickup' => ['Výdejní místo – popisek', 'Výdejní místo / box'],
            'pickup_code' => ['Kód místa – popisek', 'Kód místa'],
            'country_cz' => ['Česká republika', 'Česká republika'],
            'phone' => ['Telefon – popisek', 'Telefon'],
            'email' => ['E-mail – popisek', 'E-mail'],
            'tracking' => ['Nadpis sledování', 'Sledování zásilky'],
            'tracking_number' => ['Číslo zásilky – popisek', 'Číslo zásilky'],
            'tracking_pending' => ['Sledování ještě není k dispozici', 'Dopravce zatím nepřidělil číslo zásilky. Jakmile ho budeme mít, pošleme vám ho.'],
            'button_tracking' => ['Tlačítko sledování', 'Sledovat zásilku'],
            'button_order' => ['Tlačítko objednávky', 'Zobrazit objednávku'],
            'button_support' => ['Odkaz na zákaznickou podporu', 'Reklamace a vrácení'],
            'terms' => ['Odkaz na podmínky', 'Obchodní podmínky'],
            'returns' => ['Odkaz na vrácení', 'Výměna a vrácení zboží'],
            'complaints' => ['Odkaz na reklamační řád', 'Reklamační řád'],
        ],
        'Platba' => [
            'payment' => ['Platba – popisek', 'Platba'],
            'bank_transfer' => ['Bankovní převod – název', 'Bankovní převod'],
            'bank_heading' => ['Nadpis platebních údajů', 'Údaje pro platbu'],
            'bank_intro' => ['Pokyn k převodu', 'Prosíme o úhradu {total} na účet níže. Uveďte variabilní symbol, abychom platbu správně přiřadili.'],
            'bank_account' => ['Číslo účtu – popisek', 'Číslo účtu'],
            'variable_symbol' => ['Variabilní symbol – popisek', 'Variabilní symbol'],
            'due_date' => ['Splatnost – popisek', 'Splatnost'],
            'online_payment' => ['Online platba', 'Online přes {gateway}'],
            'online_pending' => ['Čekání na potvrzení brány', 'Jakmile nám platební brána potvrdí úhradu, dáme vám vědět. Pokud už jste zaplatili, platbu neopakujte.'],
            'payment_confirmed' => ['Potvrzení platby', 'Platba byla potvrzena.'],
            'cancelled_payment' => ['Platba při stornu', 'Pokud jste již zaplatili, odpovězte prosím na tento e-mail. Platbu dohledáme.'],
            'late_payment' => ['Upozornění na platbu po stornu', 'Platba dorazila po zrušení objednávky. Objednávka zůstává zrušená; zásilku nyní nepřipravujeme.'],
        ],
        'Partnerské dodání a asistence' => [
            'partner_heading' => ['Nadpis partnerského dodání', 'Dodání od partnera'],
            'partner_intro' => ['Vysvětlení partnerského dodání', 'U partnerských položek se postaráme o objednání, zboží odešle přímo uvedený partner. O průběhu vás budeme informovat.'],
            'partner_item' => ['Označení položky', 'Dodává {seller_name} · asistovaný nákup'],
            'partner_prices' => ['Rozpis ceny za kus', 'Zboží {goods_price} + zajištění nákupu {service_fee}. Služba je již zahrnuta v ceně.'],
            'seller' => ['Prodávající – popisek', 'Prodávající'],
            'ico' => ['IČO – popisek', 'IČO'],
            'seller_address' => ['Adresa prodávajícího – popisek', 'Adresa prodávajícího'],
            'seller_contact' => ['Kontakt prodávajícího – popisek', 'Kontakt prodávajícího'],
            'seller_terms' => ['Podmínky prodávajícího – popisek', 'Podmínky prodávajícího'],
            'assistance_terms' => ['Odkaz na podmínky asistence', 'Podmínky asistovaného nákupu'],
            'delivery_terms' => ['Sjednané dodání – popisek', 'Dodání'],
            'assistance' => ['Nadpis služby', 'Asistovaný nákup'],
            'assistance_contract' => ['Potvrzení smlouvy o službě', 'Smlouva o asistenci byla uzavřena odesláním objednávky.'],
            'early_request' => ['Souhlas s dřívějším zahájením – popisek', 'Vaše žádost o dřívější zahájení služby'],
            'assistance_wait' => ['Bez dřívějšího zahájení', 'O zahájení služby během lhůty pro odstoupení jste nepožádali. Nákup u partnera proto zajistíme po jejím uplynutí.'],
        ],
        'Faktura' => [
            'invoice_heading' => ['Nadpis faktury', 'Faktura {invoice_number}'],
            'issue_date' => ['Datum vystavení – popisek', 'Datum vystavení'],
            'supplier' => ['Dodavatel – popisek', 'Dodavatel'],
            'buyer' => ['Odběratel – popisek', 'Odběratel'],
            'address' => ['Adresa – popisek', 'Adresa'],
            'invoice_principal' => ['Vysvětlení prostředků pro partnera', 'Mimo tuto fakturu jsme přijali {principal} k nákupu zboží u partnerů. Doklad za zboží vystaví příslušný prodávající. Celá přijatá platba: {paid_total}. Neplaťte znovu.'],
            'invoice_bank' => ['Úhrada převodem', 'Uhrazeno bankovním převodem · VS: {variable_symbol}'],
            'invoice_online' => ['Úhrada přes bránu', 'Platba přes {gateway} byla při vystavení faktury potvrzená.'],
            'invoice_vat' => ['Údaj o DPH', 'Nejsem plátce DPH.'],
        ],
        'Reklamace a vrácení' => [
            'case_number' => ['Číslo případu – popisek', 'Číslo případu'],
            'case_submitted' => ['Datum podání – popisek', 'Datum a čas podání'],
            'case_item' => ['Předmět podání – popisek', 'Zboží / služba'],
            'quantity' => ['Množství – popisek', 'Množství'],
            'unit_price' => ['Cena za kus – popisek', 'Cena za kus při objednání'],
            'customer_name' => ['Jméno zákazníka – popisek', 'Jméno'],
            'company' => ['Společnost – popisek', 'Společnost'],
            'case_email' => ['Kontaktní e-mail – popisek', 'E-mail pro vyřízení'],
            'delivered_on' => ['Datum převzetí – popisek', 'Datum převzetí sdělené zákazníkem'],
            'customer_message' => ['Sdělení zákazníka – popisek', 'Sdělení zákazníka'],
            'defect' => ['Popis vady – popisek', 'Popis vady'],
            'no_message' => ['Bez sdělení', 'Bez dalšího sdělení.'],
            'requested_solution' => ['Požadované řešení – popisek', 'Požadované řešení'],
            'resolution' => ['Způsob vyřízení – popisek', 'Způsob vyřízení'],
            'repair_duration' => ['Doba opravy – popisek', 'Doba opravy'],
            'resolved_at' => ['Datum vyřízení – popisek', 'Datum vyřízení'],
            'refund_amount' => ['Vrácená částka – popisek', 'Vrácená částka'],
            'refunded_at' => ['Datum vratky – popisek', 'Vrácení zaznamenáno'],
            'case_complaint_statement' => ['Potvrzení reklamace zboží', 'Zákazník uplatnil reklamaci zboží.'],
            'case_withdrawal_statement' => ['Potvrzení odstoupení – zboží', 'Zákazník odstoupil od kupní smlouvy v rozsahu níže uvedeného zboží.'],
            'case_service_complaint' => ['Potvrzení reklamace služby', 'Zákazník uplatnil reklamaci služby asistovaného nákupu.'],
            'case_service_withdrawal' => ['Potvrzení odstoupení – služba', 'Zákazník odstoupil od smlouvy o službě asistovaného nákupu v níže uvedeném rozsahu.'],
            'button_case' => ['Tlačítko případu', 'Zobrazit případ'],
            'case_message' => ['Nadpis odpovědi', 'Naše zpráva'],
        ],
        'Účet a bezpečnost' => [
            'reset_heading' => ['Nadpis obnovy hesla', 'Nastavte si nové heslo'],
            'reset_expiry' => ['Platnost odkazu', 'Odkaz platí {minutes} minut a lze jej použít pouze jednou.'],
            'reset_ignore' => ['Nevyžádaná obnova', 'Pokud jste o změnu hesla nežádali, můžete zprávu ignorovat. Vaše původní heslo zůstává platné.'],
            'button_reset' => ['Tlačítko obnovy', 'Nastavit nové heslo'],
            'reset_fallback' => ['Náhradní odkaz', 'Nefunguje tlačítko? Zkopírujte do prohlížeče tento odkaz:'],
        ],
    ];

    public static function definitions(): array
    {
        return MailSettingsRepository::EVENTS + self::EXTRA_TEMPLATES;
    }

    public static function defaults(): array
    {
        $copy = [];
        foreach (self::GROUPS as $fields) foreach ($fields as $key => [$label, $value]) $copy[$key] = $value;
        foreach (\SimpleStore\AfterSales\CaseRepository::STATUSES as $key => $value) $copy['case_status_' . $key] = $value;
        foreach (\SimpleStore\AfterSales\CaseRepository::REMEDIES as $key => $value) $copy['remedy_' . $key] = $value;
        return $copy;
    }

    public static function copy(array $configuration = []): array
    {
        $defaults = self::defaults();
        $saved = $configuration['settings']['copy'] ?? [];
        return array_replace($defaults, is_array($saved) ? array_filter(array_intersect_key($saved, $defaults), 'is_string') : []);
    }

    public static function resolvedCopy(array $configuration = []): array
    {
        $variables = self::variables($configuration);
        return array_map(static fn (string $value): string => self::fill($value, $variables), self::copy($configuration));
    }

    public static function variables(array $configuration = []): array
    {
        return ['shop_name' => (string) ($configuration['settings']['from_name'] ?? 'dobrodruzi.cz')];
    }

    public static function fill(string $value, array $variables = []): string
    {
        $replace = [];
        foreach ($variables as $key => $replacement) $replace['{' . $key . '}'] = (string) $replacement;
        return strtr($value, $replace);
    }

    public static function template(string $code, array $configuration, array $variables = []): array
    {
        $template = $configuration['templates'][$code] ?? self::definitions()[$code];
        $variables += self::variables($configuration);
        return ['subject' => self::subject(self::fill($template['subject'], $variables)),
            'message' => self::fill($template['message'], $variables)];
    }

    public static function subject(string $subject): string
    {
        $subject = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $subject));
        while (strlen($subject) > 190) $subject = (string) preg_replace('/.$/us', '', $subject);
        return $subject;
    }

    public static function placeholders(string $code): array
    {
        if (in_array($code, ['password_reset', 'admin_password_reset', 'test'], true)) return ['shop_name'];
        if (in_array($code, ['case_receipt', 'withdrawal_receipt', 'case_event'], true)) {
            return ['shop_name', 'customer_name', 'order_number', 'case_number', 'case_status'];
        }
        if ($code === 'invoice') return ['shop_name', 'customer_name', 'order_number', 'invoice_number', 'total'];
        return ['shop_name', 'order_number', 'customer_name', 'total', 'carrier'];
    }

    public static function validatePlaceholders(string $value, array $allowed): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $value, $matches);
        foreach ($matches[1] as $key) {
            if (!in_array($key, $allowed, true)) throw new InvalidArgumentException('Neznámá zástupná značka {' . $key . '}.');
        }
    }

    public static function money(int $value): string
    {
        return number_format($value, 0, ',', ' ') . ' Kč';
    }

    public static function date(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) === 1 &&
            checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return (int) $match[3] . '. ' . (int) $match[2] . '. ' . $match[1];
        }
        return \SimpleStore\Rendering\LocalDateTime::czech($value);
    }
}
