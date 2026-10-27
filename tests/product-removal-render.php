<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$render=static function(string $mode):string {
    $argv=['fixture',$mode]; ob_start(); require __DIR__.'/fixtures/product-removal.php'; return ob_get_clean();
};
foreach (['preview','default'] as $mode) {
    $html=$render($mode);
    if (!str_contains($html,'name="phase" value="confirm"') || !str_contains($html,'name="preview_id" value="preview-token"') ||
        !str_contains($html,'name="confirm" value="1" required') || !str_contains($html,'ve všech jazycích') ||
        !str_contains($html,'&lt;bezpečný&gt;') || str_contains($html,'<bezpečný>') || str_contains($html,'name="key"')) {
        throw new RuntimeException('Deletion confirmation must reference the server preview, require explicit consent and escape names.');
    }
}
foreach (['pending','success'] as $mode) {
    $html=$render($mode);
    if (!str_contains($html,'Produkt je odstraněný z nabídky') || (str_contains($html,'name="phase" value="cleanup"')!==($mode==='pending'))) throw new RuntimeException('Cleanup retry state is misleading.');
}
$renderer=new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__).'/view');
ob_start(); $renderer->render('not-found',['basePath'=>'/shop/','language'=>'cs','removedProduct'=>['name'=>'Starý <produkt>'],'privatePage'=>true],410); $html=ob_get_clean();
if (http_response_code()!==410 || !str_contains($html,'Produkt už není v nabídce') || !str_contains($html,'Starý &lt;produkt&gt;') ||
    !str_contains($html,'name="robots" content="noindex') || str_contains($html,'data-add')) throw new RuntimeException('Removed product must render a safe, non-purchasable 410 page.');
http_response_code(200);
echo "Product removal preview, consent, retry and public tombstone rendering passed.\n";
