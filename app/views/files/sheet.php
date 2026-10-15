<?php
/** @var array $file @var array $user */
$back = url('files') . '#f/' . (int) $file['folder_id'];
$btn = static fn(string $cmd, string $icon, string $label, string $extra = '') => '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-cmd="' . $cmd . '" title="' . te($label) . '" aria-label="' . te($label) . '"' . $extra . '><i class="bi bi-' . $icon . '"></i></button>';
?>
<div class="m4w-sheet" data-sheet data-id="<?= (int) $file['id'] ?>" data-back="<?= e($back) ?>">
  <header class="m4w-sheet-bar">
    <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>"><i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline"><?= te('office.back') ?></span></a>
    <i class="bi bi-file-earmark-spreadsheet text-success fs-5"></i>
    <h1 class="m4w-sheet-name text-truncate"><?= e($file['name']) ?></h1>
    <span class="m4w-sheet-status small text-muted text-truncate" data-status role="status"></span>
    <div class="m4w-sheet-people ms-auto" data-people></div>
    <button type="button" class="btn btn-primary btn-sm text-nowrap" data-cmd="save" disabled><i class="bi bi-cloud-check"></i><span class="d-none d-sm-inline ms-1"><?= te('sheet.save') ?></span></button>
  </header>
  <div class="m4w-sheet-tools" data-tools role="toolbar" aria-label="<?= te('sheet.toolbar') ?>">
    <?= $btn('undo', 'arrow-counterclockwise', 'sheet.undo') ?><?= $btn('redo', 'arrow-clockwise', 'sheet.redo') ?>
    <span class="sep"></span>
    <?= $btn('b', 'type-bold', 'sheet.bold') ?><?= $btn('i', 'type-italic', 'sheet.italic') ?><?= $btn('u', 'type-underline', 'sheet.underline') ?><?= $btn('s', 'type-strikethrough', 'sheet.strike') ?>
    <label class="m4w-sheet-color" title="<?= te('sheet.text_color') ?>"><i class="bi bi-fonts"></i><input type="color" data-color="fc" value="#000000" aria-label="<?= te('sheet.text_color') ?>"></label>
    <label class="m4w-sheet-color" title="<?= te('sheet.fill_color') ?>"><i class="bi bi-paint-bucket"></i><input type="color" data-color="bg" value="#fff2cc" aria-label="<?= te('sheet.fill_color') ?>"></label>
    <?= $btn('nofill', 'eraser', 'sheet.no_fill') ?>
    <span class="sep"></span>
    <?= $btn('left', 'text-left', 'sheet.align_left') ?><?= $btn('center', 'text-center', 'sheet.align_center') ?><?= $btn('right', 'text-right', 'sheet.align_right') ?><?= $btn('wrap', 'text-wrap', 'sheet.wrap') ?>
    <span class="sep"></span>
    <select class="form-select form-select-sm m4w-sheet-nf" data-nf aria-label="<?= te('sheet.number_format') ?>">
      <?php foreach (array_keys(M4W\Service\Sheet::FORMATS) as $k): ?><option value="<?= $k ?>"><?= te('sheet.nf_' . $k) ?></option><?php endforeach; ?>
    </select>
    <?= $btn('border', 'border-all', 'sheet.borders') ?><?= $btn('merge', 'union', 'sheet.merge') ?>
    <span class="sep"></span>
    <?= $btn('sum', 'calculator', 'sheet.autosum') ?>
    <div class="dropdown d-inline-block">
      <button type="button" class="btn btn-ghost btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-grid-3x3 me-1"></i><?= te('sheet.rows_cols') ?></button>
      <ul class="dropdown-menu">
        <li><button class="dropdown-item" data-cmd="ins_row_above"><?= te('sheet.ins_row_above') ?></button></li>
        <li><button class="dropdown-item" data-cmd="ins_row_below"><?= te('sheet.ins_row_below') ?></button></li>
        <li><button class="dropdown-item" data-cmd="del_row"><?= te('sheet.del_row') ?></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-cmd="ins_col_left"><?= te('sheet.ins_col_left') ?></button></li>
        <li><button class="dropdown-item" data-cmd="ins_col_right"><?= te('sheet.ins_col_right') ?></button></li>
        <li><button class="dropdown-item" data-cmd="del_col"><?= te('sheet.del_col') ?></button></li>
      </ul>
    </div>
  </div>
  <div class="m4w-sheet-formula">
    <input class="form-control form-control-sm m4w-sheet-addr" data-addr aria-label="<?= te('sheet.cell') ?>" spellcheck="false">
    <span class="fx">fx</span>
    <input class="form-control form-control-sm m4w-sheet-fx" data-fx aria-label="<?= te('sheet.content') ?>" spellcheck="false" autocomplete="off">
  </div>
  <div class="m4w-sheet-notice d-none" data-notice role="note"></div>
  <div class="m4w-sheet-grid" data-grid>
    <div class="m4w-sheet-corner"></div>
    <div class="m4w-sheet-colhead" data-colhead></div>
    <div class="m4w-sheet-rowhead" data-rowhead></div>
    <div class="m4w-sheet-scroll" data-scroll tabindex="0" role="region" aria-label="<?= te('sheet.grid') ?>">
      <div class="m4w-sheet-inner" data-inner>
        <div class="m4w-sheet-cells" data-cells></div>
        <div class="m4w-sheet-others" data-others></div>
        <div class="m4w-sheet-sel" data-sel><span class="handle"></span></div>
        <textarea class="m4w-sheet-editor d-none" data-editor spellcheck="false" aria-label="<?= te('sheet.content') ?>"></textarea>
      </div>
    </div>
    <div class="m4w-sheet-loading" data-loading><span class="spinner-border text-primary"></span></div>
  </div>
  <footer class="m4w-sheet-tabs">
    <div class="tabs" data-tabs role="tablist"></div>
    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-cmd="sheet_add" title="<?= te('sheet.add_sheet') ?>" aria-label="<?= te('sheet.add_sheet') ?>"><i class="bi bi-plus-lg"></i></button>
    <span class="ms-auto small text-muted d-none d-md-inline" data-sum></span>
  </footer>
  <div class="dropdown-menu m4w-sheet-ctx" data-ctx></div>
</div>
<script src="<?= e(asset('js/sheet.js')) ?>" defer></script>
