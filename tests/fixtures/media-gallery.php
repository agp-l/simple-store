<?php
declare(strict_types=1);
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product gallery fixture</title><link rel="stylesheet" href="/assets/media.css"><style>body{font-family:Arial,sans-serif}button{font:inherit}</style></head><body>
<button type="button" onclick="SimpleStoreMedia.open('gallery-add')">Spravovat fotografie</button>
<?php require dirname(__DIR__, 2) . '/view/media/picker.php'; ?>
<script>window.galleryRevision=4; window.SimpleStoreMediaContext={endpoint:'/admin/',csrf:'fixture-token',type:'product',key:'a'.repeat(32),language:'cs',basePath:'/',getRevision:async()=>window.galleryRevision,uploaded:result=>{window.galleryRevision=result.revision;}};</script>
<script src="/assets/media-manager.js"></script></body></html>
