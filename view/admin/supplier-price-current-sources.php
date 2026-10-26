<?php if (!empty($job['current_sources'])): ?>
<div class="supplier-current-sources">
  <strong><?= \SimpleStore\Rendering\Icon::render('external') ?> Aktuální partnerské odkazy</strong>
  <?php foreach (array_chunk($job['current_sources'], 3) as $chunkIndex => $sources): ?>
    <?php if ($chunkIndex === 1): ?><details><summary>Další aktuální nabídky (<?= count($job['current_sources']) - 3 ?>)</summary><?php endif; ?>
    <ul><?php foreach ($sources as $source): ?><li>
      <a href="<?= $escape($source['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($source['name']) ?><?= $source['selected'] ? ' · vybraný partner' : '' ?> ↗</a>
      <small><?= $escape($source['url']) ?></small>
    </li><?php endforeach; ?></ul>
  <?php endforeach; ?>
  <?php if (count($job['current_sources']) > 3): ?></details><?php endif; ?>
</div>
<?php elseif (!empty($job['configuration_issue'])): ?>
<p class="panel-notice"><?= $escape($job['configuration_issue']) ?></p>
<?php endif; ?>
