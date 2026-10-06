/*! Make4WebMail — file space pickers (choose files to attach, choose a destination folder) */
(function ($, window) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;

  function modal(title, okLabel) {
    var $m = $('<div class="modal fade" tabindex="-1" aria-hidden="true">'
      + '<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title fs-5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + esc(t('fs_close')) + '"></button></div>'
      + '<div class="modal-body p-0 fs-picker"></div>'
      + '<div class="modal-footer"><span class="me-auto small text-muted" data-status></span><button type="button" class="btn btn-light" data-bs-dismiss="modal">' + esc(t('fs_cancel')) + '</button><button type="button" class="btn btn-primary" data-ok disabled></button></div>'
      + '</div></div></div>');
    $m.find('.modal-title').text(title);
    $m.find('[data-ok]').text(okLabel);
    $m.appendTo(document.body).on('hidden.bs.modal', function () { $m.remove(); });
    return $m;
  }

  /** Destination folder among those the user may write to. Resolves with the folder id. */
  function pickFolder(title, okLabel, opts) {
    opts = opts || {};
    var d = $.Deferred(), $m = modal(title, okLabel || t('fs_choose')), chosen = null;
    var $body = $m.find('.fs-picker').html('<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm"></span></div>');
    M4W.get('api/files/tree').done(function (r) {
      var html = '<ul class="fs-tree" role="listbox" aria-label="' + esc(title) + '">';
      r.items.forEach(function (f) {
        var disabled = !f.writable || (opts.exclude && opts.exclude.indexOf(f.id) !== -1);
        var icon = f.root === 'personal' ? 'person-workspace' : (f.root === 'space' ? 'collection' : (f.root === 'shared' ? 'people' : 'folder'));
        html += '<li><button type="button" role="option" class="fs-tree-item" data-id="' + f.id + '"' + (disabled ? ' disabled' : '')
          + ' style="padding-left:' + (0.9 + f.depth * 1.2) + 'rem"><i class="bi bi-' + icon + '"></i><span class="text-truncate">' + esc(f.name) + '</span>'
          + (!f.writable ? '<span class="badge badge-soft-secondary ms-auto">' + esc(t('fs_read_only')) + '</span>' : '') + '</button></li>';
      });
      $body.html(html + '</ul>');
      if (opts.current) $body.find('.fs-tree-item[data-id="' + opts.current + '"]:not(:disabled)').trigger('click');
    });
    $m.on('click', '.fs-tree-item', function () {
      $m.find('.fs-tree-item').removeClass('active').attr('aria-selected', 'false');
      $(this).addClass('active').attr('aria-selected', 'true');
      chosen = +$(this).data('id');
      $m.find('[data-ok]').prop('disabled', false);
    }).on('dblclick', '.fs-tree-item:not(:disabled)', function () { $m.find('[data-ok]').trigger('click'); });
    $m.find('[data-ok]').on('click', function () { d.resolve(chosen); bootstrap.Modal.getInstance($m[0]).hide(); });
    $m.on('hidden.bs.modal', function () { if (d.state() === 'pending') d.reject(); });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
    return d.promise();
  }

  /** Browse the file space and tick files. Resolves with an array of file ids. */
  function pickFiles(title, okLabel) {
    var d = $.Deferred(), $m = modal(title || t('fs_attach_title'), okLabel || t('fs_attach')), selected = {};
    var $body = $m.find('.fs-picker');
    function count() {
      var n = Object.keys(selected).length;
      $m.find('[data-ok]').prop('disabled', !n);
      $m.find('[data-status]').text(n ? t('fs_selected', { n: n }) : '');
    }
    function roots() {
      $body.html('<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm"></span></div>');
      M4W.get('api/files/roots').done(function (r) {
        var html = '<ul class="fs-tree">' + row('folder', r.personal, t('fs_my_files'), 'person-workspace');
        r.spaces.forEach(function (s) { html += row('folder', s.id, s.name, 'collection'); });
        r.shared.forEach(function (s) { html += row('folder', s.id, s.name, 'people'); });
        $body.html(html + '</ul>');
      });
    }
    function row(type, id, name, icon, extra) {
      return '<li><button type="button" class="fs-tree-item" data-type="' + type + '" data-id="' + id + '"><i class="bi bi-' + icon + '"></i><span class="text-truncate">' + esc(name) + '</span>' + (extra || '') + '</button></li>';
    }
    function open(id) {
      $body.html('<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm"></span></div>');
      M4W.get('api/files/folder/' + id).done(function (r) {
        var crumbs = '<button type="button" class="btn btn-link btn-sm p-0" data-root>' + esc(t('fs_all')) + '</button>';
        r.path.forEach(function (p) { crumbs += ' <i class="bi bi-chevron-right small text-muted"></i> <button type="button" class="btn btn-link btn-sm p-0" data-open="' + p.id + '">' + esc(p.name) + '</button>'; });
        var html = '<div class="fs-picker-crumbs">' + crumbs + '</div><ul class="fs-tree">';
        if (!r.folders.length && !r.files.length) html += '<li class="p-4 text-center text-muted small">' + esc(t('fs_empty_folder')) + '</li>';
        r.folders.forEach(function (f) { html += row('folder', f.id, f.name, 'folder-fill text-warning'); });
        r.files.forEach(function (f) {
          var ic = M4W.fileIcon(f.name, f.mime)[1];
          html += '<li><label class="fs-tree-item"><input type="checkbox" class="form-check-input m-0" data-file="' + f.id + '"' + (selected[f.id] ? ' checked' : '') + '>'
            + '<i class="bi bi-' + ic + '"></i><span class="text-truncate">' + esc(f.name) + '</span><span class="ms-auto small text-muted">' + esc(M4W.bytes(f.size)) + '</span></label></li>';
        });
        $body.html(html + '</ul>');
      });
    }
    $m.on('click', '.fs-tree-item[data-type=folder]', function () { open($(this).data('id')); })
      .on('click', '[data-open]', function () { open($(this).data('open')); })
      .on('click', '[data-root]', roots)
      .on('change', 'input[data-file]', function () { var id = $(this).data('file'); if (this.checked) selected[id] = 1; else delete selected[id]; count(); });
    $m.find('[data-ok]').on('click', function () { d.resolve(Object.keys(selected).map(Number)); bootstrap.Modal.getInstance($m[0]).hide(); });
    $m.on('hidden.bs.modal', function () { if (d.state() === 'pending') d.reject(); });
    roots();
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
    return d.promise();
  }

  M4W.FilesPicker = { pickFolder: pickFolder, pickFiles: pickFiles };
})(jQuery, window);
