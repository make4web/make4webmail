<div class="m4w-nav-title"><?= te('nav.contacts') ?></div>
<ul class="m4w-nav">
  <li><a class="m4w-nav-item<?= ($tab ?? 'mine') === 'mine' ? ' active' : '' ?>" href="<?= e(url('contacts')) ?>"><i class="bi bi-person-lines-fill"></i><span class="m4w-nav-label"><?= te('contacts.mine') ?></span></a></li>
  <li><a class="m4w-nav-item<?= ($tab ?? '') === 'directory' ? ' active' : '' ?>" href="<?= e(url('contacts', ['tab' => 'directory'])) ?>"><i class="bi bi-building"></i><span class="m4w-nav-label"><?= te('contacts.directory') ?></span></a></li>
</ul>
