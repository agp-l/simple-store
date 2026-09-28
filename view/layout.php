<?php
declare(strict_types=1);
// Údaje pro pohled předává vstupní skript; později jej může nahradit kontroler.
?>
<!doctype html>
<html lang="cs">
<?php require __DIR__ . '/head.php'; ?>
<body>
<?php require __DIR__ . '/header.php'; ?>
<?php require __DIR__ . ($page === 'product' ? '/product-body.php' : '/body.php'); ?>
<?php require __DIR__ . '/footer.php'; ?>
<?php require __DIR__ . '/cart.php'; ?>
<script defer src="assets/app.js"></script>
<script defer src="assets/product.js"></script>
</body>
</html>
