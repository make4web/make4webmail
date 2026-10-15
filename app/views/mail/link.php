<?php
/** @var string $href @var string $host @var string $registrable @var string $text @var string $shown @var string[] $problems @var string $threat @var array|null $idn */
$blocked = $threat !== '';
$prefix = $registrable !== '' && str_ends_with($host, $registrable) ? substr($host, 0, -strlen($registrable)) : '';
?>
<main class="m4w-linkcheck">
  <div class="m4w-linkcheck-card <?= $blocked ? 'is-blocked' : 'is-warn' ?>" role="alert">
    <div class="icon"><i class="bi bi-<?= $blocked ? 'shield-fill-x' : 'exclamation-triangle-fill' ?>"></i></div>
    <?php $idnBlock = in_array($threat, ['HOMOGRAPH', 'IDN'], true); ?>
    <h1><?= te($threat === 'INVALID' ? 'link.invalid_title' : ($idnBlock ? 'link.blocked_title_' . strtolower($threat) : ($blocked ? 'link.blocked_title' : 'link.warn_title'))) ?></h1>
    <?php if ($threat === 'INVALID'): ?>
      <p><?= te('link.invalid_text') ?></p>
    <?php else: ?>
      <p class="mb-2"><?= te($idnBlock ? 'link.blocked_text_idn' : ($blocked ? 'link.blocked_text' : 'link.warn_text')) ?></p>
      <div class="dest">
        <div class="label"><?= te('link.real_destination') ?></div>
        <div class="host"><?php if ($host === ''): ?>—<?php else: ?><span class="sub"><?= e($prefix) ?></span><strong><?= e($registrable ?: $host) ?></strong><?php endif; ?></div>
        <div class="url m4w-code"><?= e(mb_strimwidth($display_href ?? $href, 0, 160, '…')) ?></div>
        <?php if (!empty($idn)): ?>
          <div class="label mt-2"><?= te('link.idn_as_read') ?></div><div class="m4w-idn m4w-code"><?= $idn['html'] ?></div>
          <div class="label mt-2"><?= te('link.idn_real') ?></div><div class="m4w-code"><?= e($idn['ascii']) ?></div>
          <?php if ($idn['imitates']): ?><div class="mt-2 fw-semibold text-danger"><?= te('link.idn_imitates', ['name' => $idn['imitates']]) ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($text !== '' && $text !== $href): ?><div class="label mt-2"><?= te('link.shown_text') ?></div><div class="shown">« <?= e(mb_strimwidth($text, 0, 120, '…')) ?> »</div><?php endif; ?>
      </div>
      <ul class="reasons">
        <?php if ($blocked): ?><li><i class="bi bi-bug"></i><?= te('link.t.' . (in_array($threat, ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION', 'URLHAUS', 'ADMIN_BLOCK', 'HOMOGRAPH', 'IDN'], true) ? $threat : 'OTHER')) ?></li><?php endif; ?>
        <?php foreach ($problems as $p): ?><li><i class="bi bi-dot"></i><?= te('link.p.' . $p, ['shown' => $shown, 'real' => $p === 'homograph' && !empty($idn['imitates']) ? $idn['imitates'] : ($registrable ?: $host)]) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="actions">
      <a class="btn btn-primary" href="<?= e(url('mail')) ?>"><i class="bi bi-arrow-left me-1"></i><?= te('link.back') ?></a>
      <?php if (!$blocked && $href !== ''): ?><a class="btn btn-link text-danger" href="<?= e($href) ?>" rel="noopener noreferrer nofollow"><?= te('link.open_anyway', ['host' => $host]) ?></a><?php endif; ?>
    </div>
    <?php if (!$blocked && $href !== ''): ?><p class="small text-muted mt-3 mb-0"><?= te('link.tip') ?></p><?php endif; ?>
  </div>
</main>
