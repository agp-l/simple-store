<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;
use InvalidArgumentException;
final class EvidenceExamples
{
    public static function document(string $kind): array {
        $examples=[
            'proforma'=>['VZOR-ZAL-2026-001','2026-10-01',null,'2026-10-08',null,'Záloha na boty – 1 pár','1800.00','Objednáno 1. 10. Datum vystavení výzvy není datem příjmu.'],
            'invoice'=>['VZOR-ESH-2026-001','2026-10-04','2026-10-04','2026-10-08','2026-10-03','Boty – 1 pár','1800.00','Peníze připsány 3. 10., zboží prodáno/dodáno 4. 10. Peněžní příjem patří do 3. 10.'],
            'receipt'=>['VZOR-MAS-2026-001','2026-10-05','2026-10-05',null,'2026-10-05','Přijato v hotovosti za masáž, 60 minut','800.00','Datum služby a skutečného přijetí hotovosti je v tomto příkladu stejné.'],
            'expense'=>['VZOR-VYD-2026-001','2026-10-02','2026-10-02','2026-10-12','2026-10-06','Obaly pro e-shop – 1 sada','242.00','Dodavatel vystavil doklad 2. 10., skutečná úhrada proběhla 6. 10. Výdaj patří do 6. 10.; uschovej originál dodavatele.'],
            'bank_statement'=>['VZOR-BANK-2026-10','2026-10-31',null,null,null,'Výukový podklad: příjem 1 800 Kč dne 3. 10., výdaj 242 Kč dne 6. 10.','0.00','Toto není výpis Fio ani jiné banky. Stáhni skutečný PDF výpis z internetbankingu a připoj jej k odpovídajícím platbám. Datum výpisu nenahrazuje data jednotlivých transakcí.'],
            'correction'=>['VZOR-OPR-2026-001','2026-10-10','2026-10-10',null,'2026-10-11','Vrácení ceny bot k VZOR-ESH-2026-001','1800.00','Oprava vystavena 10. 10., peníze vráceny 11. 10. Do deníku se 11. 10. zapíše vratka zdanitelného příjmu. Původní fakturu ponech.'],
        ];
        if (!isset($examples[$kind])) throw new InvalidArgumentException('Neznámý vzor.');
        [$number,$issued,$delivered,$due,$paid,$description,$amount,$notes]=$examples[$kind];
        return ['example'=>true,'kind'=>$kind,'document_number'=>$number,'issue_date'=>$issued,'delivery_date'=>$delivered,'due_date'=>$due,'paid_date'=>$paid,'description'=>$description,'amount_czk'=>$amount,'notes'=>$notes,'activity'=>$kind==='receipt'?'Masáže':'E-shop','counterparty'=>'Ukázkový zákazník / dodavatel – fiktivní údaje','seller_json'=>json_encode(['name'=>'VZOR – podnikatel','ico'=>'00000000','street'=>'Ukázková 1','city'=>'Ukázkové město','postal_code'=>'00000'],JSON_THROW_ON_ERROR)];
    }
}
