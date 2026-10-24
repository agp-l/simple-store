<?php
// Included only behind the administrator session, action allowlist and CSRF validation.
use SimpleStore\Accounting\DocumentNumbering;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Accounting\TaxRegisterRepository;
use SimpleStore\Accounting\EvidenceDocumentRepository;
use SimpleStore\Accounting\EvidenceJournal;
use SimpleStore\Accounting\EvidenceArchive;
use SimpleStore\Accounting\EvidenceExamples;
use SimpleStore\Accounting\InvoiceEditor;
use SimpleStore\Database\CommerceTestReset;

$documentRepository=new EvidenceDocumentRepository($db);
$workspaceReady=$documentRepository->installed();
$evidenceJournal=new EvidenceJournal($db);
$numbering=new DocumentNumbering($db);
$journalPage=['items'=>[],'nextOffset'=>null];$journalTotals=[];$journalActivities=[];
$documentSeries=$documentFiles=$documentHistory=$documentEntries=$documentEntryOptions=$invoiceEditHistory=[];
$evidenceDocument=null;$resetReport=null;$journalOffset=0;$journalSearch=$journalActivity='';
$seriesNextNumbers=[];
$evidenceActionHandled=false;
$workspaceActions=['evidence-document-save','evidence-series-save','evidence-invoice-save','evidence-commerce-reset'];
$workspaceUrl=$adminUrl.'?section=accounting';
try {
    TaxEvidenceRepository::year($taxYear);
    if ($method==='POST' && in_array($_POST['action']??'', $workspaceActions,true)) {
        $evidenceActionHandled=true;
        if (!$workspaceReady) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        $admin=$auth->user();if($admin===null) throw new RuntimeException('Přihlášení vypršelo.');
        $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($id===false)throw new InvalidArgumentException('Neplatný doklad.');
        $destination=$workspaceUrl.'&year='.$taxYear.'&saved=1';
        switch($_POST['action']) {
            case 'evidence-document-save':
                $id=$documentRepository->save($id,$_POST,$taxSettings,(int)$admin['id'],EvidenceDocumentRepository::upload($_FILES['document_file']??null));
                $destination.='&tab=documents&document_id='.$id;break;
            case 'evidence-series-save':
                $numbering->save($id,$_POST,(int)$admin['id']);$destination.='&tab=numbering';break;
            case 'evidence-invoice-save':
                (new InvoiceEditor($db))->save($id,$_POST,(int)$admin['id']);$destination.='&tab=invoices&invoice_id='.$id;break;
            case 'evidence-commerce-reset':
                if(($_POST['confirmed']??'')!=='1' || ($_POST['confirmation']??'')!=='SMAZAT HISTORII' ||
                    !is_string($_POST['password']??null) || !password_verify($_POST['password'],$admin['password_hash'])) throw new InvalidArgumentException('Potvrď trvalé smazání textem SMAZAT HISTORII, zaškrtni potvrzení a zadej své současné heslo správce.');
                $reset=new CommerceTestReset($db);$report=$reset->report();
                if(!is_string($_POST['report_hash']??null) || !hash_equals(hash('sha256',json_encode($report,JSON_THROW_ON_ERROR)),$_POST['report_hash'])) throw new InvalidArgumentException('Od otevření přehledu se historie změnila. Zkontroluj aktuální počty a potvrď znovu.');
                $reset->apply($report['database'],true);$destination.='&tab=restart&reset_done=1';break;
        }
        header('Location: '.\SimpleStore\Navigation\PanelUrl::pretty($destination),true,303);exit;
    }
    if($workspaceReady) {
        TaxEvidenceRepository::year($taxYear);
        $documentSeries=$numbering->all();
        if ($accountingTab==='numbering') $seriesNextNumbers=$numbering->nextNumbers($taxYear);
        if(isset($_GET['sample'])) {
            $evidenceDocument=EvidenceExamples::document(is_string($_GET['sample'])?$_GET['sample']:'');
            require __DIR__.'/../../view/admin/evidence-document-print.php';exit;
        }
        if(isset($_GET['evidence_file'])) {
            $file=$documentRepository->file((int)$_GET['evidence_file']);
            if($file===null){http_response_code(404);exit('Soubor nebyl nalezen.');}
            header('Content-Type: '.$file['mime_type']);header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="doklad-'.$file['id'].'.'.strtolower(pathinfo($file['filename'],PATHINFO_EXTENSION)).'"; filename*=UTF-8\'\''.rawurlencode($file['filename']));
            header('Content-Length: '.strlen($file['file_data']));echo $file['file_data'];exit;
        }
        $journalSearch=TaxRegisterRepository::text($_GET['q']??'',150);
        $journalActivity=TaxRegisterRepository::text($_GET['activity']??'',120);
        if(($_GET['download']??'')==='journal-csv') {
            $stream=fopen('php://temp','w+b');
            try{$evidenceJournal->csv($stream,$taxYear,$journalSearch,$journalActivity,$adminUrl);rewind($stream);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="danova-evidence-'.$taxYear.'.csv"');fpassthru($stream);}finally{fclose($stream);}exit;
        }
        if(($_GET['download']??'')==='documents-zip') {
            $archive=(new EvidenceArchive($db))->build($taxYear,($_GET['scope']??'')==='all');
            try{header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="evidence-'.(($_GET['scope']??'')==='all'?'vse':$taxYear).'.zip"');header('Content-Length: '.filesize($archive['path']));readfile($archive['path']);}finally{EvidenceArchive::cleanup($archive['directory']);}exit;
        }
        if(isset($_GET['print_entry'])) {
            $entry=$tax->entry((int)$_GET['print_entry']);if($entry===null)throw new InvalidArgumentException('Peněžní zápis nebyl nalezen.');
            $evidenceDocument=EvidenceArchive::entry($entry,$taxSettings);require __DIR__.'/../../view/admin/evidence-document-print.php';exit;
        }
        if($accountingTab==='documents' || isset($_GET['print_document'])) {
            $id=(int)($_GET['document_id']??$_GET['print_document']??$_POST['id']??0);
            $evidenceDocument=$id>0?$documentRepository->byId($id):null;
            if($id>0 && $evidenceDocument===null)throw new InvalidArgumentException('Doklad nebyl nalezen.');
            if(isset($_GET['print_document'])){if($evidenceDocument===null)throw new InvalidArgumentException('Vyber doklad.');require __DIR__.'/../../view/admin/evidence-document-print.php';exit;}
            if($evidenceDocument!==null){$documentFiles=$documentRepository->files($id);$documentHistory=$documentRepository->history('document',$id);$documentEntries=$documentRepository->linkedEntries($id);}
            elseif(isset($_GET['entry_id']))$documentEntries=[(int)$_GET['entry_id']];
            $documentEntryOptions=$documentRepository->recentEntries();
            // Preserve older selected movements outside the recent dropdown window.
            foreach(array_diff($documentEntries,array_map('intval',array_column($documentEntryOptions,'id'))) as $entryId){$entry=$tax->entry($entryId);if($entry!==null)$documentEntryOptions[]=$entry;}
        }
        if($accountingTab==='overview') {
            $journalOffset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000000]]);
            if($journalOffset===false)throw new InvalidArgumentException('Neplatná stránka deníku.');
            $journalPage=$evidenceJournal->page($taxYear,$journalOffset,$journalSearch,$journalActivity);
            $journalTotals=$evidenceJournal->totals($taxYear,$journalSearch,$journalActivity);$journalActivities=$evidenceJournal->activities();
            foreach($journalPage['items'] as &$journalRow)$journalRow['links']=$evidenceJournal->links($journalRow);unset($journalRow);
        }
        if($accountingTab==='invoices' && isset($_GET['invoice_id']))$invoiceEditHistory=$documentRepository->history('invoice',(int)$_GET['invoice_id']);
        if($accountingTab==='restart')$resetReport=(new CommerceTestReset($db))->report();
    }
} catch(InvalidArgumentException $e){http_response_code(422);$accountingError=$e->getMessage();}
catch(RuntimeException $e){http_response_code(503);$accountingError=$e->getMessage();}
// Load the original record after validation failure so edit forms retain their identity/version.
if($workspaceReady && $evidenceActionHandled && $accountingError!=='') {
    if(($_POST['action']??'')==='evidence-document-save') {
        $accountingTab='documents';$evidenceDocument=$documentRepository->byId((int)($_POST['id']??0));
        $documentEntryOptions=$documentRepository->recentEntries();$documentSeries=$numbering->all();
        $selectedIds=is_array($_POST['entry_ids']??null)?array_filter(array_map(static fn($id)=>is_scalar($id)?filter_var($id,FILTER_VALIDATE_INT):false,$_POST['entry_ids'])):[];
        foreach(array_diff($selectedIds,array_map('intval',array_column($documentEntryOptions,'id'))) as $entryId){$entry=$tax->entry((int)$entryId);if($entry!==null)$documentEntryOptions[]=$entry;}
        if($evidenceDocument!==null){$documentFiles=$documentRepository->files((int)$evidenceDocument['id']);$documentHistory=$documentRepository->history('document',(int)$evidenceDocument['id']);}
    }
    if(($_POST['action']??'')==='evidence-commerce-reset'){$accountingTab='restart';$resetReport=(new CommerceTestReset($db))->report();}
    if(($_POST['action']??'')==='evidence-series-save'){$accountingTab='numbering';$documentSeries=$numbering->all();$seriesNextNumbers=$numbering->nextNumbers($taxYear);}
}
