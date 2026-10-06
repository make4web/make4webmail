<div class="px-2 mb-2"><div class="m4w-help-search">
  <i class="bi bi-search" aria-hidden="true"></i>
  <input type="search" class="form-control form-control-sm" placeholder="Chercher dans la notice" aria-label="Chercher dans la notice" data-help-search>
</div></div>
<div class="m4w-nav-title">Utiliser la messagerie</div>
<ul class="m4w-nav" data-help-toc>
<?php foreach ($toc['user'] as $id => [$icon, $label]): ?>
  <li><a class="m4w-nav-item" href="#<?= e($id) ?>"><i class="bi bi-<?= e($icon) ?>"></i><span class="m4w-nav-label"><?= e($label) ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php if ($toc['admin']): ?>
<div class="m4w-nav-title">Administration</div>
<ul class="m4w-nav" data-help-toc>
<?php foreach ($toc['admin'] as $id => [$icon, $label]): ?>
  <li><a class="m4w-nav-item" href="#<?= e($id) ?>"><i class="bi bi-<?= e($icon) ?>"></i><span class="m4w-nav-label"><?= e($label) ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
