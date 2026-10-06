/*! Make4WebMail — shared file space */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;
  var $app = $('#fs-app');
  if (!$app.length) return;

  var MAX = +$app.data('max-file') || 0;
  var S = {
    view: 'list', route: 'mine', folder: null, level: 'none', roots: null,
    items: [], selected: {}, sort: 'name', dir: 1, lastIndex: -1, q: ''
  };
  try { S.view = localStorage.getItem('m4w.fs.view') || 'list'; } catch (e) { /* storage unavailable */ }

  var LEVEL = { none: 0, read: 1, write: 2, manage: 3 };
  var PREVIEW_IMG = /^image\/(png|jpeg|gif|webp|bmp)$/;
  var PREVIEW = /^(image\/(png|jpeg|gif|webp|bmp)|application\/pdf|text\/plain|audio\/(mpeg|ogg|wav)|video\/(mp4|webm))$/;

  function can(level) { return LEVEL[S.level] >= LEVEL[level]; }
  function fileUrl(f, inline, version) {
    var q = {};
    if (inline) q.inline = 1;
    if (version) q.version = version;
    return M4W.url('files/file/' + f.id, q);
  }
  function itemKey(it) { return it.type + ':' + it.id; }
  function selectedItems() { return S.items.filter(function (it) { return S.selected[itemKey(it)]; }); }

  // ------------------------------------------------------------------ sidebar
  function loadRoots() {
    return M4W.get('api/files/roots', null, { silent: true }).done(function (r) {
      S.roots = r;
      var html = '';
      r.spaces.forEach(function (s) {
        html += '<li class="m4w-nav-li"><a class="m4w-nav-item" href="#f/' + s.id + '" data-fs-folder="' + s.id + '"><i class="bi bi-collection"></i><span class="m4w-nav-label">' + esc(s.name) + '</span>'
          + (s.level === 'read' ? '<i class="bi bi-eye small opacity-50" title="' + esc(t('fs_read_only')) + '"></i>' : '') + '</a></li>';
      });
      if (!r.spaces.length) html = '<li class="px-3 py-1 small m4w-sidebar-muted">' + esc(t('fs_no_spaces')) + '</li>';
      $('#fs-nav-spaces').html(html);
      $('[data-fs-space-only]').toggleClass('d-none', !r.can_create_space);
      $('#fs-trash-count').text(r.trash || '');
      var u = r.usage;
      if (u.quota > 0) {
        var pct = Math.min(100, Math.round(u.used / u.quota * 100));
        $('#fs-usage').html('<div class="progress" role="progressbar" aria-label="' + esc(t('fs_storage')) + '" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar' + (pct > 90 ? ' bg-danger' : (pct > 75 ? ' bg-warning' : '')) + '" style="width:' + Math.max(pct, 1) + '%"></div></div>'
          + esc(t('fs_usage', { used: M4W.bytes(u.used), total: M4W.bytes(u.quota) })));
      } else {
        $('#fs-usage').html('<i class="bi bi-hdd"></i> ' + esc(t('fs_usage_unlimited', { used: M4W.bytes(u.used) })));
      }
      markNav();
    });
  }
  function markNav() {
    $('.m4w-sidebar .m4w-nav-item').removeClass('active').removeAttr('aria-current');
    var $a;
    if (S.route === 'folder' && S.path && S.path.length) {
      var root = S.path[0];
      if (root.kind === 'personal') $a = $('[data-fs-nav="mine"]');
      else if (root.kind === 'space') $a = $('[data-fs-folder="' + root.id + '"]');
      else $a = $('[data-fs-nav="shared"]');
    } else {
      $a = $('[data-fs-nav="' + S.route + '"]');
    }
    if ($a && $a.length) $a.addClass('active').attr('aria-current', 'page');
  }

  // ------------------------------------------------------------------ routing
  function go() {
    var h = (window.location.hash || '').replace(/^#/, '');
    var m;
    clearSelection(true);
    $('body').removeClass('sidebar-open');
    if ((m = /^f\/(\d+)$/.exec(h))) { openFolder(+m[1]); }
    else if (h === 'shared') { openShared(); }
    else if (h === 'trash') { openTrash(); }
    else if ((m = /^search\/(.+)$/.exec(h))) { openSearch(decodeURIComponent(m[1])); }
    else { // mine
      var mine = function () { openFolder(S.roots.personal, 'mine'); };
      if (S.roots) mine(); else loadRoots().done(mine);
    }
  }

  function setHeader(path, sub) {
    var html = '';
    (path || []).forEach(function (p, i) {
      var last = i === path.length - 1;
      html += '<li>' + (last ? '<h1 class="fs-crumb-current" aria-current="page">' + esc(p.name) + '</h1>'
        : '<a href="' + (p.href || '#f/' + p.id) + '" data-drop-folder="' + (p.id || '') + '">' + esc(p.name) + '</a>') + '</li>';
    });
    $('#fs-crumbs').html(html);
    $('#fs-subheader').html(sub || '');
    document.title = (path && path.length ? path[path.length - 1].name + ' · ' : '') + t('fs_title');
  }

  function toolbar() {
    var trash = S.route === 'trash';
    $('#fs-toolbar [data-need]').each(function () {
      var need = $(this).data('need'), show;
      if (need === 'trash') show = trash && S.items.length > 0;
      else if (need === 'manage-space') show = S.route === 'folder' && S.current && S.current.kind === 'space' && can('manage');
      else if (need === 'manage') show = S.route === 'folder' && can('manage') && S.current && S.current.kind !== 'personal';
      else show = S.route === 'folder' && can(need);
      $(this).toggleClass('d-none', !show);
    });
    $('#fs-new-btn').prop('disabled', S.route === 'folder' ? !can('write') : false);
  }

  function openFolder(id, route) {
    S.route = 'folder';
    S.folder = id;
    S.q = '';
    $('#fs-search').val('');
    loading();
    return M4W.get('api/files/folder/' + id, null, { quiet: true }).done(function (r) {
      S.level = r.folder.level;
      S.current = r.folder;
      S.path = r.path;
      var items = [];
      r.folders.forEach(function (f) { f.type = 'folder'; items.push(f); });
      r.files.forEach(function (f) { f.type = 'file'; items.push(f); });
      S.items = items;
      var sub = '';
      if (r.folder.kind === 'space') {
        sub = (r.folder.description ? '<span>' + esc(r.folder.description) + '</span>' : '')
          + '<span class="badge badge-soft-secondary">' + esc(t('fs_level_' + r.folder.level)) + '</span>';
      } else if (r.path.length && r.path[0].kind !== 'personal' && r.path[0].kind !== 'space') {
        sub = '<span class="badge badge-soft-secondary">' + esc(t('fs_level_' + r.folder.level)) + '</span>';
      } else if (r.folder.kind === 'personal') {
        sub = '<span>' + esc(t('fs_mine_desc')) + '</span>';
      }
      if (r.folder.shared && r.folder.kind !== 'space') sub += '<span class="badge badge-soft-primary"><i class="bi bi-people me-1"></i>' + esc(t('fs_shared_badge')) + '</span>';
      setHeader(r.path, sub);
      toolbar();
      render();
      markNav();
    }).fail(function (x) {
      var msg = (x && x.responseJSON && x.responseJSON.error) || t('error_network');
      S.items = []; S.level = 'none'; setHeader([{ name: t('fs_title') }]); toolbar();
      $('#fs-body').html(empty('exclamation-circle', msg, ''));
    });
  }

  function openShared() {
    S.route = 'shared'; S.level = 'none'; S.current = null; loading();
    loadRoots().done(function () {
      S.items = S.roots.shared.map(function (f) { f.type = 'folder'; return f; });
      setHeader([{ name: t('fs_shared_with_me') }], '<span>' + esc(t('fs_shared_desc')) + '</span>');
      toolbar(); render(); markNav();
    });
  }

  function openTrash() {
    S.route = 'trash'; S.level = 'none'; S.current = null; loading();
    M4W.get('api/files/trash').done(function (r) {
      S.items = r.items;
      setHeader([{ name: t('fs_trash') }], '<span>' + esc(t('fs_trash_desc', { days: S.roots ? S.roots.trash_days : 30 })) + '</span>');
      toolbar(); render(); markNav();
    });
  }

  function openSearch(q) {
    S.route = 'search'; S.level = 'none'; S.current = null; S.q = q; loading();
    $('#fs-search').val(q);
    M4W.get('api/files/search', { q: q }).done(function (r) {
      var items = [];
      r.folders.forEach(function (f) { f.type = 'folder'; items.push(f); });
      r.files.forEach(function (f) { f.type = 'file'; items.push(f); });
      S.items = items;
      setHeader([{ name: t('fs_search_results', { q: q }) }]);
      toolbar(); render(); markNav();
    });
  }

  // ------------------------------------------------------------------ render
  function loading() {
    $('#fs-body').html('<div class="fs-loading"><span class="spinner-border text-primary" role="status"><span class="visually-hidden">' + esc(t('loading')) + '</span></span></div>');
  }
  function empty(icon, title, hint, action) {
    return '<div class="m4w-empty py-5"><div class="m4w-empty-icon"><i class="bi bi-' + icon + '"></i></div><h3>' + esc(title) + '</h3>'
      + (hint ? '<p class="small">' + esc(hint) + '</p>' : '') + (action || '') + '</div>';
  }

  function sorted() {
    var k = S.sort, dir = S.dir;
    return S.items.slice().sort(function (a, b) {
      if (S.route !== 'trash' && a.type !== b.type) return a.type === 'folder' ? -1 : 1;
      var va, vb;
      if (k === 'size') { va = a.size || 0; vb = b.size || 0; }
      else if (k === 'date') { va = a.deleted_at || a.updated_at || 0; vb = b.deleted_at || b.updated_at || 0; }
      else { return dir * String(a.name).localeCompare(String(b.name), undefined, { numeric: true, sensitivity: 'base' }); }
      return dir * (va - vb);
    });
  }

  function icon(it) {
    if (it.type === 'folder') {
      var ic = it.kind === 'space' ? 'collection-fill' : (it.shared ? 'folder-symlink-fill' : 'folder-fill');
      return '<span class="fs-icon folder"><i class="bi bi-' + ic + '"></i></span>';
    }
    var fi = M4W.fileIcon(it.name, it.mime);
    if (S.view === 'grid' && PREVIEW_IMG.test(it.mime) && S.route !== 'trash' && it.size < 15 * 1048576) {
      return '<span class="fs-icon thumb"><img src="' + esc(fileUrl(it, true)) + '" alt="" loading="lazy"></span>';
    }
    return '<span class="fs-icon m4w-att-icon ' + fi[0] + '"><i class="bi bi-' + fi[1] + '"></i></span>';
  }

  function render() {
    var items = sorted();
    S.lastIndex = -1;
    $('#fs-app').toggleClass('is-grid', S.view === 'grid');
    $('[data-fs-view]').removeClass('active').attr('aria-pressed', 'false').filter('[data-fs-view="' + S.view + '"]').addClass('active').attr('aria-pressed', 'true');
    if (!items.length) {
      var html;
      if (S.route === 'trash') html = empty('trash3', t('fs_trash_empty'), t('fs_trash_empty_hint'));
      else if (S.route === 'shared') html = empty('people', t('fs_shared_empty'), t('fs_shared_empty_hint'));
      else if (S.route === 'search') html = empty('search', t('fs_search_empty'), '');
      else if (can('write')) html = empty('cloud-arrow-up', t('fs_folder_empty'), t('fs_folder_empty_hint'),
        '<div class="d-flex gap-2 justify-content-center mt-2"><button class="btn btn-primary" type="button" data-fs="upload"><i class="bi bi-upload me-1"></i>' + esc(t('fs_upload')) + '</button><button class="btn btn-light" type="button" data-fs="new-folder"><i class="bi bi-folder-plus me-1"></i>' + esc(t('fs_new_folder')) + '</button></div>');
      else html = empty('folder2-open', t('fs_folder_empty'), '');
      $('#fs-body').html(html);
      updateSelection();
      return;
    }
    var trash = S.route === 'trash', search = S.route === 'search' || S.route === 'shared';
    var html2;
    if (S.view === 'grid') {
      html2 = '<div class="fs-grid" role="list">';
      items.forEach(function (it, i) {
        html2 += '<div class="fs-tile" role="listitem" data-i="' + i + '" data-key="' + itemKey(it) + '" draggable="' + (!trash && can('write') ? 'true' : 'false') + '" tabindex="0">'
          + '<input type="checkbox" class="form-check-input fs-check" aria-label="' + esc(t('fs_select_item', { name: it.name })) + '">'
          + icon(it) + '<div class="fs-tile-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
          + '<div class="fs-tile-meta">' + esc(it.type === 'file' ? M4W.bytes(it.size) : (it.kind === 'space' ? t('fs_space') : t('fs_folder'))) + '</div>'
          + '<button type="button" class="btn btn-ghost btn-icon btn-sm fs-more" aria-label="' + esc(t('fs_actions')) + '"><i class="bi bi-three-dots-vertical"></i></button></div>';
      });
      html2 += '</div>';
    } else {
      var arrow = function (k) { return S.sort === k ? ' <i class="bi bi-arrow-' + (S.dir > 0 ? 'up' : 'down') + '"></i>' : ''; };
      var ariaSort = function (k) { return S.sort === k ? (S.dir > 0 ? 'ascending' : 'descending') : 'none'; };
      html2 = '<table class="table table-hover fs-table"><thead><tr>'
        + '<th class="fs-col-check"><input type="checkbox" class="form-check-input" id="fs-check-all" aria-label="' + esc(t('fs_select_all')) + '"></th>'
        + '<th aria-sort="' + ariaSort('name') + '"><button type="button" class="fs-sort" data-sort="name">' + esc(t('fs_name')) + arrow('name') + '</button></th>'
        + (trash || search ? '<th class="d-none d-lg-table-cell">' + esc(t(trash ? 'fs_origin' : 'fs_location')) + '</th>' : '')
        + '<th class="d-none d-md-table-cell" aria-sort="' + ariaSort('date') + '"><button type="button" class="fs-sort" data-sort="date">' + esc(t(trash ? 'fs_deleted_on' : 'fs_modified')) + arrow('date') + '</button></th>'
        + '<th class="d-none d-sm-table-cell text-end" aria-sort="' + ariaSort('size') + '"><button type="button" class="fs-sort" data-sort="size">' + esc(t('fs_size')) + arrow('size') + '</button></th>'
        + '<th class="fs-col-more"><span class="visually-hidden">' + esc(t('fs_actions')) + '</span></th></tr></thead><tbody>';
      items.forEach(function (it, i) {
        var who = trash ? it.deleted_by : (it.type === 'file' ? it.updated_by : it.created_by);
        var when = trash ? it.deleted_at : it.updated_at;
        var badges = '';
        if (it.type === 'file' && it.version > 1) badges += '<span class="badge badge-soft-secondary ms-2" title="' + esc(t('fs_versions')) + '">v' + it.version + '</span>';
        if (it.type === 'folder' && it.shared && S.route !== 'shared') badges += '<i class="bi bi-people ms-2 text-muted small" title="' + esc(t('fs_shared_badge')) + '"></i>';
        if (it.owner) badges += '<span class="small text-muted ms-2">' + esc(it.owner) + '</span>';
        html2 += '<tr data-i="' + i + '" data-key="' + itemKey(it) + '" draggable="' + (!trash && can('write') ? 'true' : 'false') + '">'
          + '<td class="fs-col-check"><input type="checkbox" class="form-check-input fs-check" aria-label="' + esc(t('fs_select_item', { name: it.name })) + '"></td>'
          + '<td><div class="fs-name">' + icon(it) + '<a href="' + (it.type === 'folder' && !trash ? '#f/' + it.id : '#') + '" class="fs-open text-truncate" data-open>' + esc(it.name) + '</a>' + badges + '</div></td>'
          + (trash || search ? '<td class="d-none d-lg-table-cell small text-muted text-truncate fs-col-path">' + esc(it.origin || it.path || '') + '</td>' : '')
          + '<td class="d-none d-md-table-cell small text-muted text-nowrap">' + esc(M4W.date(when)) + (who ? ' · ' + esc(who) : '') + '</td>'
          + '<td class="d-none d-sm-table-cell small text-muted text-end text-nowrap">' + (it.type === 'file' ? esc(M4W.bytes(it.size)) : '—') + '</td>'
          + '<td class="fs-col-more"><button type="button" class="btn btn-ghost btn-icon btn-sm fs-more" aria-label="' + esc(t('fs_actions_for', { name: it.name })) + '"><i class="bi bi-three-dots"></i></button></td></tr>';
      });
      html2 += '</tbody></table>';
    }
    $('#fs-body').html(html2);
    updateSelection();
  }

  // ---------------------------------------------------------------- selection
  function $rows() { return $('#fs-body [data-key]'); }
  function updateSelection() {
    var sel = selectedItems(), n = sel.length;
    $rows().each(function () {
      var on = !!S.selected[$(this).data('key')];
      $(this).toggleClass('selected', on).find('.fs-check').prop('checked', on);
    });
    $('#fs-check-all').prop('checked', n > 0 && n === S.items.length).prop('indeterminate', n > 0 && n < S.items.length);
    $('#fs-toolbar').toggleClass('has-selection', n > 0);
    $('#fs-sel-count').text(t('fs_selected', { n: n }));
    var files = sel.filter(function (it) { return it.type === 'file'; }).length;
    var trash = S.route === 'trash';
    var writable = !trash && sel.every(function (it) { return itemWritable(it); });
    $('#fs-toolbar [data-sel]').each(function () {
      var need = $(this).data('sel'), show;
      if (need === 'trash') show = trash;
      else if (trash) show = false;
      else if (need === 'files') show = files > 0 && files === n;
      else if (need === 'write') show = writable;
      else if (need === 'one-write') show = n === 1 && writable;
      else show = true;
      $(this).toggleClass('d-none', !show);
    });
  }
  function itemWritable(it) {
    if (S.route === 'folder') {
      if (it.type === 'folder' && it.kind === 'space') return LEVEL[it.level] >= 3;
      return can('write');
    }
    // search / shared views: rights of the item itself (folders) — files are checked server-side
    if (it.type === 'folder') return LEVEL[it.level] >= (it.kind === 'space' ? 3 : 2) && S.route !== 'shared';
    return false;
  }
  function clearSelection(silent) { S.selected = {}; if (!silent) updateSelection(); }
  function toggle(it, on) { var k = itemKey(it); if (on === undefined) on = !S.selected[k]; if (on) S.selected[k] = 1; else delete S.selected[k]; }
  function itemAt(i) { return sorted()[i]; }

  // ------------------------------------------------------------------ actions
  function open(it) {
    if (S.route === 'trash') return;
    if (it.type === 'folder') { window.location.hash = 'f/' + it.id; return; }
    preview(it);
  }

  function preview(f) {
    var $m = $('#fs-preview-modal'), $b = $('#fs-preview-body').empty();
    $('#fs-preview-title').text(f.name);
    $('#fs-preview-dl').attr('href', fileUrl(f));
    if (!PREVIEW.test(f.mime)) { window.location.href = fileUrl(f); return; }
    var src = fileUrl(f, true);
    if (/^image\//.test(f.mime)) $b.append($('<img alt="">').attr('src', src).attr('alt', f.name));
    else if (/^audio\//.test(f.mime)) $b.append($('<audio controls></audio>').attr('src', src));
    else if (/^video\//.test(f.mime)) $b.append($('<video controls></video>').attr('src', src));
    else $b.append($('<iframe sandbox="" referrerpolicy="no-referrer"></iframe>').attr('title', f.name).attr('src', src));
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  }
  $('#fs-preview-modal').on('hidden.bs.modal', function () { $('#fs-preview-body').empty(); });

  function askName(title, value, okLabel, withDesc, desc) {
    var d = $.Deferred(), $m = $('#fs-name-modal');
    $('#fs-name-title').text(title);
    $('#fs-name-ok').text(okLabel || t('fs_save'));
    $('#fs-name-input').val(value || '');
    $('#fs-desc-wrap').toggleClass('d-none', !withDesc);
    $('#fs-desc-input').val(desc || '');
    $('#fs-name-form').off('submit').on('submit', function (e) {
      e.preventDefault();
      var v = String($('#fs-name-input').val()).trim();
      if (!v) return;
      d.resolve(v, String($('#fs-desc-input').val()).trim());
      bootstrap.Modal.getInstance($m[0]).hide();
    });
    $m.off('shown.bs.modal').on('shown.bs.modal', function () {
      var $i = $('#fs-name-input')[0], v = $i.value, dot = v.lastIndexOf('.');
      $i.focus();
      $i.setSelectionRange(0, dot > 0 && !withDesc ? dot : v.length);
    }).off('hidden.bs.modal').on('hidden.bs.modal', function () { if (d.state() === 'pending') d.reject(); });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
    return d.promise();
  }

  function refresh() {
    loadRoots();
    if (S.route === 'folder') return openFolder(S.folder);
    if (S.route === 'trash') return openTrash();
    if (S.route === 'shared') return openShared();
    if (S.route === 'search') return openSearch(S.q);
  }

  function payload(items) { return items.map(function (it) { return { type: it.type, id: it.id }; }); }

  var actions = {
    'new-folder': function () {
      if (S.route !== 'folder' || !can('write')) { M4W.toast(t('fs_pick_folder_first'), 'info'); return; }
      askName(t('fs_new_folder'), t('fs_untitled_folder'), t('fs_create')).done(function (name) {
        M4W.post('api/files/folders', { parent_id: S.folder, name: name }).done(function () { M4W.toast(t('fs_folder_created'), 'success'); refresh(); });
      });
    },
    'new-space': function () {
      askName(t('fs_new_space'), '', t('fs_create'), true).done(function (name, desc) {
        M4W.post('api/files/spaces', { name: name, description: desc }).done(function (r) {
          M4W.toast(t('fs_space_created'), 'success');
          loadRoots().done(function () { window.location.hash = 'f/' + r.id; });
        });
      });
    },
    'edit-space': function () {
      var c = S.current;
      askName(t('fs_edit_space'), c.name, t('fs_save'), true, c.description).done(function (name, desc) {
        M4W.post('api/files/space/' + c.id, { name: name, description: desc }).done(function () { M4W.toast(t('fs_saved'), 'success'); refresh(); });
      });
    },
    upload: function () {
      if (S.route !== 'folder' || !can('write')) { M4W.toast(t('fs_pick_folder_first'), 'info'); return; }
      $('#fs-input').val('').trigger('click');
    },
    'upload-dir': function () {
      if (S.route !== 'folder' || !can('write')) { M4W.toast(t('fs_pick_folder_first'), 'info'); return; }
      $('#fs-input-dir').val('').trigger('click');
    },
    'zip-folder': function () { window.location.href = M4W.url('files/folder/' + S.folder + '/zip'); },
    download: function (items) {
      items = items || selectedItems();
      items.filter(function (it) { return it.type === 'file'; }).forEach(function (f, i) {
        setTimeout(function () { var a = document.createElement('a'); a.href = fileUrl(f); a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }, i * 400);
      });
      items.filter(function (it) { return it.type === 'folder'; }).forEach(function (f) { window.location.href = M4W.url('files/folder/' + f.id + '/zip'); });
    },
    'attach-mail': function (items) {
      var ids = (items || selectedItems()).filter(function (it) { return it.type === 'file'; }).map(function (f) { return f.id; });
      if (ids.length) window.location.href = M4W.url('mail', { compose: 1, files: ids.join(',') });
    },
    rename: function (items) {
      var it = (items || selectedItems())[0];
      if (!it) return;
      askName(t('fs_rename'), it.name, t('fs_rename')).done(function (name) {
        M4W.post('api/files/rename', { type: it.type, id: it.id, name: name }).done(function () { M4W.toast(t('fs_renamed'), 'success'); clearSelection(true); refresh(); });
      });
    },
    move: function (items) { transfer(items || selectedItems(), 'move'); },
    copy: function (items) { transfer(items || selectedItems(), 'copy'); },
    delete: function (items) {
      items = items || selectedItems();
      if (!items.length) return;
      M4W.post('api/files/delete', { items: payload(items) }).done(function (r) {
        clearSelection(true); refresh();
        M4W.toast(t('fs_moved_trash', { n: r.count }), 'success', {
          action: { label: t('undo'), fn: function () { M4W.post('api/files/restore', { items: payload(items) }).done(refresh); } }
        });
      });
    },
    restore: function (items) {
      items = items || selectedItems();
      M4W.post('api/files/restore', { items: payload(items) }).done(function (r) { clearSelection(true); refresh(); M4W.toast(t('fs_restored', { n: r.count }), 'success'); });
    },
    purge: function (items) {
      items = items || selectedItems();
      M4W.confirm(t('fs_purge_title'), t('fs_purge_text', { n: items.length }), t('fs_purge')).done(function () {
        M4W.post('api/files/purge', { items: payload(items) }).done(function (r) { clearSelection(true); refresh(); M4W.toast(t('fs_purged', { n: r.count }), 'success'); });
      });
    },
    'empty-trash': function () { actions.purge(S.items.slice()); },
    share: function (items) {
      var it = items && items[0];
      shareDialog(it && it.type === 'folder' ? it : S.current);
    },
    versions: function (items) { versionsDialog((items || selectedItems())[0]); },
    'copy-link': function (items) {
      var it = (items || selectedItems())[0];
      var link = window.location.origin + M4W.url('files') + (it.type === 'folder' ? '#f/' + it.id : '#f/' + it.folder_id);
      if (navigator.clipboard) navigator.clipboard.writeText(link).then(function () { M4W.toast(t('copied'), 'success'); });
    },
    'open-location': function (items) { var it = (items || selectedItems())[0]; window.location.hash = 'f/' + (it.type === 'file' ? it.folder_id : (it.parent_id || it.id)); },
    'clear-selection': function () { clearSelection(); },
    'close-uploads': function () { $('#fs-uploads').prop('hidden', true); $('#fs-uploads-list').empty(); }
  };

  function transfer(items, mode) {
    if (!items.length) return;
    var exclude = items.filter(function (it) { return it.type === 'folder'; }).map(function (it) { return it.id; });
    M4W.FilesPicker.pickFolder(t(mode === 'move' ? 'fs_move_title' : 'fs_copy_title', { n: items.length }), t(mode === 'move' ? 'fs_move' : 'fs_copy'), { exclude: exclude, current: S.folder })
      .done(function (target) {
        M4W.post('api/files/' + mode, { items: payload(items), target: target }).done(function (r) {
          clearSelection(true); refresh();
          M4W.toast(t(mode === 'move' ? 'fs_moved' : 'fs_copied', { n: r.count }), 'success');
        });
      });
  }

  // ------------------------------------------------------------ context menu
  function menuFor(items) {
    var one = items.length === 1 ? items[0] : null, trash = S.route === 'trash';
    var writable = !trash && items.every(itemWritable);
    var files = items.every(function (it) { return it.type === 'file'; });
    var m = [];
    if (trash) return [['restore', 'arrow-counterclockwise', 'fs_restore'], ['purge', 'x-octagon', 'fs_purge', 'danger']];
    if (one) m.push(['open', one.type === 'folder' ? 'folder2-open' : 'eye', one.type === 'folder' ? 'fs_open' : 'fs_preview']);
    m.push(['download', 'download', 'fs_download']);
    if (files) m.push(['attach-mail', 'envelope', 'fs_send_mail']);
    if (one && one.type === 'folder' && LEVEL[one.level] >= 3 && one.kind !== 'personal') m.push(['share', 'person-plus', 'fs_share']);
    if (S.route === 'search' && one) m.push(['open-location', 'folder-symlink', 'fs_open_location']);
    m.push('-');
    if (one && writable) m.push(['rename', 'input-cursor-text', 'fs_rename']);
    if (writable) m.push(['move', 'folder-symlink', 'fs_move']);
    m.push(['copy', 'copy', 'fs_copy']);
    if (one && one.type === 'file') m.push(['versions', 'clock-history', 'fs_versions']);
    if (one) m.push(['copy-link', 'link-45deg', 'fs_copy_link']);
    if (writable) { m.push('-'); m.push(['delete', 'trash3', 'fs_delete', 'danger']); }
    return m;
  }
  function showMenu(x, y, items) {
    var $c = $('#fs-ctx').empty();
    menuFor(items).forEach(function (m) {
      if (m === '-') { if ($c.children().length && !$c.children().last().is('hr')) $c.append('<hr class="dropdown-divider">'); return; }
      $('<button type="button" class="dropdown-item" role="menuitem"></button>').toggleClass('text-danger', m[3] === 'danger')
        .html('<i class="bi bi-' + m[1] + '"></i>' + esc(t(m[2]))).on('click', function () {
          hideMenu();
          if (m[0] === 'open') open(items[0]); else actions[m[0]](items);
        }).appendTo($c);
    });
    $c.addClass('show').css({ left: 0, top: 0 });
    var w = $c.outerWidth(), h = $c.outerHeight();
    $c.css({ left: Math.max(8, Math.min(x, window.innerWidth - w - 8)), top: Math.max(8, Math.min(y, window.innerHeight - h - 8)) });
    $c.find('.dropdown-item').first().trigger('focus');
  }
  function hideMenu() { $('#fs-ctx').removeClass('show'); }
  $(document).on('click', function (e) { if (!$(e.target).closest('#fs-ctx').length) hideMenu(); })
    .on('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
  $('#fs-ctx').on('keydown', function (e) {
    var $i = $(this).find('.dropdown-item'), idx = $i.index(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); $i.eq((idx + 1) % $i.length).trigger('focus'); }
    if (e.key === 'ArrowUp') { e.preventDefault(); $i.eq((idx - 1 + $i.length) % $i.length).trigger('focus'); }
  });

  // ---------------------------------------------------------------- sharing
  var share = { folder: null, entries: [] };
  function shareDialog(folder) {
    if (!folder) return;
    share.folder = folder;
    M4W.get('api/files/folder/' + folder.id + '/acl').done(function (r) {
      share.entries = r.entries;
      $('#fs-share-title').text(t('fs_share_title', { name: r.folder.name }));
      renderAcl();
      var inh = '';
      if (r.owner) inh += '<div class="fs-acl-row"><span class="m4w-avatar sm" style="background:' + M4W.color(r.owner) + '">' + esc(M4W.initials(r.owner)) + '</span><div class="flex-grow-1"><div class="fw-medium">' + esc(r.owner) + '</div></div><span class="small text-muted">' + esc(t('fs_owner')) + '</span></div>';
      r.inherited.forEach(function (e) { inh += aclRow(e, true); });
      $('#fs-acl-inherited').html(inh ? '<div class="m4w-nav-title px-0">' + esc(t('fs_inherited')) + '</div><ul class="fs-acl-list">' + inh + '</ul>' : '');
      $('#fs-share-q').val('');
      bootstrap.Modal.getOrCreateInstance($('#fs-share-modal')[0]).show();
    });
  }
  function aclRow(e, readonly, i) {
    var av = e.type === 'all' ? '<span class="m4w-avatar sm fs-av-icon"><i class="bi bi-globe2"></i></span>'
      : (e.type === 'department' ? '<span class="m4w-avatar sm fs-av-icon"><i class="bi bi-diagram-3"></i></span>' : M4W.avatar(e.label, e.email, 'sm'));
    var right = readonly ? '<span class="small text-muted">' + esc(t('fs_level_' + e.level)) + (e.from ? ' · ' + esc(t('fs_from', { name: e.from })) : '') + '</span>'
      : '<select class="form-select form-select-sm w-auto" data-acl-level="' + i + '" aria-label="' + esc(t('fs_level_for', { name: e.label })) + '">'
        + ['read', 'write', 'manage'].map(function (l) { return '<option value="' + l + '"' + (e.level === l ? ' selected' : '') + '>' + esc(t('fs_level_' + l)) + '</option>'; }).join('')
        + '</select><button type="button" class="btn btn-ghost btn-icon btn-sm" data-acl-remove="' + i + '" aria-label="' + esc(t('fs_remove_access', { name: e.label })) + '"><i class="bi bi-x-lg"></i></button>';
    return '<li class="fs-acl-row">' + av + '<div class="flex-grow-1 min-w-0"><div class="fw-medium text-truncate">' + esc(e.label) + '</div>' + (e.email ? '<div class="small text-muted text-truncate">' + esc(e.email) + '</div>' : '') + '</div>' + right + '</li>';
  }
  function renderAcl() {
    $('#fs-acl-list').html(share.entries.length ? share.entries.map(function (e, i) { return aclRow(e, false, i); }).join('')
      : '<li class="small text-muted py-2">' + esc(t('fs_not_shared')) + '</li>');
  }
  function addEntry(e) {
    var dup = share.entries.some(function (x) { return x.type === e.type && String(x.principal) === String(e.principal); });
    if (!dup) share.entries.push($.extend({ level: 'read' }, e));
    renderAcl();
    $('#fs-share-q').val('').trigger('focus');
    $('#fs-share-suggest').removeClass('show');
  }
  var suggest = [];
  $('#fs-share-q').on('input', M4W.debounce(function () {
    var q = String($(this).val()).trim();
    if (q.length < 1) { $('#fs-share-suggest').removeClass('show'); return; }
    M4W.get('api/files/principals', { q: q }, { silent: true }).done(function (r) {
      suggest = r.items;
      var html = r.items.map(function (e, i) {
        return '<button type="button" class="dropdown-item d-flex align-items-center gap-2" data-suggest="' + i + '">'
          + (e.type === 'user' ? M4W.avatar(e.label, e.email, 'sm') : '<span class="m4w-avatar sm fs-av-icon"><i class="bi bi-diagram-3"></i></span>')
          + '<span class="min-w-0"><span class="d-block text-truncate">' + esc(e.label) + '</span>' + (e.email ? '<small class="text-muted">' + esc(e.email) + '</small>' : '') + '</span></button>';
      }).join('') || '<span class="dropdown-item-text small text-muted">' + esc(t('fs_no_match')) + '</span>';
      $('#fs-share-suggest').html(html).addClass('show');
    });
  }, 200)).on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); var $f = $('#fs-share-suggest [data-suggest]').first(); if ($f.length) $f.trigger('click'); }
    if (e.key === 'ArrowDown') { e.preventDefault(); $('#fs-share-suggest [data-suggest]').first().trigger('focus'); }
  });
  $('#fs-share-modal').on('click', '[data-suggest]', function () { addEntry(suggest[+$(this).data('suggest')]); })
    .on('change', '[data-acl-level]', function () { share.entries[+$(this).data('acl-level')].level = this.value; })
    .on('click', '[data-acl-remove]', function () { share.entries.splice(+$(this).data('acl-remove'), 1); renderAcl(); });
  actions['share-everyone'] = function () { addEntry({ type: 'all', principal: '*', label: t('fs_everyone'), email: '' }); };
  actions['share-save'] = function () {
    M4W.post('api/files/folder/' + share.folder.id + '/acl', { entries: share.entries.map(function (e) { return { type: e.type, principal: e.principal, level: e.level }; }) })
      .done(function () { bootstrap.Modal.getInstance($('#fs-share-modal')[0]).hide(); M4W.toast(t('fs_access_saved'), 'success'); refresh(); });
  };

  // --------------------------------------------------------------- versions
  function versionsDialog(f) {
    if (!f) return;
    $('#fs-versions-title').text(t('fs_versions_of', { name: f.name }));
    M4W.get('api/files/file/' + f.id + '/versions').done(function (r) {
      var html = r.items.map(function (v) {
        var dl = fileUrl(f, false, v.version_id || null);
        return '<tr><td><span class="fw-medium">v' + v.version + '</span>' + (v.current ? ' <span class="badge badge-soft-primary ms-1">' + esc(t('fs_current')) + '</span>' : '') + '</td>'
          + '<td class="small">' + esc(M4W.date(v.updated_at, true)) + (v.updated_by ? '<div class="text-muted">' + esc(v.updated_by) + '</div>' : '') + '</td>'
          + '<td class="small text-muted">' + esc(M4W.bytes(v.size)) + '</td>'
          + '<td class="text-end text-nowrap"><a class="btn btn-ghost btn-icon btn-sm" href="' + esc(dl) + '" title="' + esc(t('fs_download')) + '" aria-label="' + esc(t('fs_download')) + '"><i class="bi bi-download"></i></a>'
          + (!v.current && itemWritable(f) ? '<button type="button" class="btn btn-soft btn-sm ms-1" data-restore-version="' + v.version_id + '">' + esc(t('fs_restore')) + '</button>' : '') + '</td></tr>';
      }).join('');
      $('#fs-versions-body').html(html);
      $('#fs-versions-modal').off('click', '[data-restore-version]').on('click', '[data-restore-version]', function () {
        M4W.post('api/files/file/' + f.id + '/restore-version', { version_id: +$(this).data('restore-version') }).done(function () {
          bootstrap.Modal.getInstance($('#fs-versions-modal')[0]).hide(); M4W.toast(t('fs_version_restored'), 'success'); refresh();
        });
      });
      bootstrap.Modal.getOrCreateInstance($('#fs-versions-modal')[0]).show();
    });
  }

  // ---------------------------------------------------------------- uploads
  var queue = [], running = 0, uploadTotal = 0, uploadDone = 0;
  function enqueue(files, folder) {
    if (!files.length) return;
    var names = {};
    S.items.forEach(function (it) { if (it.type === 'file') names[it.name.toLowerCase()] = 1; });
    var clash = folder === S.folder ? files.filter(function (f) { return !(f.webkitRelativePath || f.relPath) && names[f.name.toLowerCase()]; }) : [];
    var go2 = function (replace) {
      files.forEach(function (f) {
        if (MAX && f.size > MAX) { M4W.toast(t('fs_too_big', { name: f.name, max: M4W.bytes(MAX) }), 'danger'); return; }
        queue.push({ file: f, folder: folder, replace: replace && names[f.name.toLowerCase()], rel: f.relPath || f.webkitRelativePath || '' });
        uploadTotal++;
      });
      $('#fs-uploads').prop('hidden', false);
      pump();
    };
    if (!clash.length) { go2(false); return; }
    $('#fs-conflict-text').text(t('fs_conflict_text', { n: clash.length, name: clash[0].name }));
    var $m = $('#fs-conflict-modal'), chosen = false;
    $m.find('[data-conflict]').off('click').on('click', function () { chosen = true; bootstrap.Modal.getInstance($m[0]).hide(); go2($(this).data('conflict') === 'replace'); });
    $m.off('hidden.bs.modal').on('hidden.bs.modal', function () { if (!chosen) { /* cancelled */ } });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  }
  function uploadTitle() {
    $('#fs-uploads-title').text(running || queue.length ? t('fs_uploading', { done: uploadDone, total: uploadTotal }) : t('fs_uploaded', { n: uploadDone }));
  }
  function pump() {
    uploadTitle();
    while (running < 3 && queue.length) send(queue.shift());
  }
  function send(job) {
    running++;
    var $li = $('<li class="fs-up"><span class="fs-up-name text-truncate"></span><span class="fs-up-state small text-muted"></span><div class="progress"><div class="progress-bar"></div></div></li>');
    $li.find('.fs-up-name').text(job.rel || job.file.name);
    $('#fs-uploads-list').prepend($li);
    var fd = new FormData();
    fd.append('file', job.file, job.file.name);
    fd.append('folder_id', job.folder);
    if (job.rel) fd.append('relpath', job.rel);
    if (job.replace) fd.append('replace', '1');
    M4W.api('POST', 'api/files/upload', fd, {
      silent: true, quiet: true,
      xhr: function () {
        var x = new window.XMLHttpRequest();
        x.upload.addEventListener('progress', function (e) { if (e.lengthComputable) $li.find('.progress-bar').css('width', Math.round(e.loaded / e.total * 100) + '%'); });
        return x;
      }
    }).done(function () {
      uploadDone++;
      $li.addClass('done').find('.fs-up-state').html('<i class="bi bi-check-circle-fill text-success"></i>');
    }).fail(function (x) {
      $li.addClass('failed').find('.fs-up-state').text((x && x.responseJSON && x.responseJSON.error) || t('error_network'));
    }).always(function () {
      running--;
      if (!running && !queue.length) { uploadTitle(); refresh(); } else pump();
    });
  }
  $('#fs-input, #fs-input-dir').on('change', function () { enqueue(Array.prototype.slice.call(this.files), S.folder); });

  // Folder drops: walk the dropped entries (Chrome, Firefox, Safari).
  function collect(dt) {
    var d = $.Deferred(), out = [], pending = 0;
    var items = dt.items ? Array.prototype.slice.call(dt.items) : [];
    var entries = items.map(function (i) { return i.webkitGetAsEntry ? i.webkitGetAsEntry() : null; }).filter(Boolean);
    if (!entries.length) { d.resolve(Array.prototype.slice.call(dt.files || [])); return d.promise(); }
    function done() { if (--pending === 0) d.resolve(out); }
    function walk(entry, path) {
      pending++;
      if (entry.isFile) {
        entry.file(function (f) { if (path) f.relPath = path + f.name; out.push(f); done(); }, done);
      } else if (entry.isDirectory) {
        var reader = entry.createReader(), all = [];
        var read = function () {
          reader.readEntries(function (batch) {
            if (batch.length) { all = all.concat(batch); read(); return; }
            all.forEach(function (e) { walk(e, path + entry.name + '/'); });
            done();
          }, done);
        };
        read();
      } else done();
    }
    entries.forEach(function (e) { walk(e, ''); });
    return d.promise();
  }

  var dragDepth = 0, dragItems = null;
  $(document).on('dragenter', function (e) {
    var dt = e.originalEvent.dataTransfer;
    if (dragItems || !dt || Array.prototype.indexOf.call(dt.types || [], 'Files') === -1) return;
    dragDepth++;
    var ok = S.route === 'folder' && can('write');
    $('#fs-drop').addClass('show').toggleClass('denied', !ok);
    $('#fs-drop-label').text(ok ? t('fs_drop_here', { name: S.current ? S.current.name : '' }) : t('fs_drop_denied'));
  }).on('dragleave', function () {
    if (dragItems) return;
    if (--dragDepth <= 0) { dragDepth = 0; $('#fs-drop').removeClass('show'); }
  }).on('dragover', function (e) {
    if (dragItems) return;
    var dt = e.originalEvent.dataTransfer;
    if (dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1) e.preventDefault();
  }).on('drop', function (e) {
    if (dragItems) return;
    var dt = e.originalEvent.dataTransfer;
    if (!dt || !dt.files || !dt.files.length) return;
    e.preventDefault();
    dragDepth = 0; $('#fs-drop').removeClass('show');
    if (S.route !== 'folder' || !can('write')) return;
    var folder = S.folder;
    collect(dt).done(function (files) { enqueue(files, folder); });
  });

  // Internal drag & drop: move selected items onto a folder row, tile or breadcrumb.
  $('#fs-body').on('dragstart', '[data-key]', function (e) {
    var it = itemAt(+$(this).data('i'));
    if (!S.selected[itemKey(it)]) { clearSelection(true); toggle(it, true); updateSelection(); }
    dragItems = selectedItems();
    e.originalEvent.dataTransfer.effectAllowed = 'move';
    e.originalEvent.dataTransfer.setData('text/plain', dragItems.map(function (i) { return i.name; }).join(', '));
  }).on('dragend', '[data-key]', function () { dragItems = null; $('.drop-hover').removeClass('drop-hover'); });
  function dropTarget(el) {
    var $el = $(el);
    if ($el.is('[data-drop-folder]')) return +$el.data('drop-folder') || null;
    if ($el.is('.m4w-nav-item[data-fs-folder]')) return +$el.data('fs-folder');
    if ($el.is('[data-fs-nav="mine"]') && S.roots) return S.roots.personal;
    var it = itemAt(+$el.data('i'));
    return it && it.type === 'folder' ? it.id : null;
  }
  $(document).on('dragover', '#fs-body [data-key], #fs-crumbs [data-drop-folder], .m4w-nav-item[data-fs-folder], [data-fs-nav="mine"]', function (e) {
    if (!dragItems) return;
    var target = dropTarget(this);
    if (!target || dragItems.some(function (i) { return i.type === 'folder' && i.id === target; })) return;
    e.preventDefault();
    $(this).addClass('drop-hover');
  }).on('dragleave', '#fs-body [data-key], #fs-crumbs [data-drop-folder], .m4w-nav-item[data-fs-folder], [data-fs-nav="mine"]', function () { $(this).removeClass('drop-hover'); })
    .on('drop', '#fs-body [data-key], #fs-crumbs [data-drop-folder], .m4w-nav-item[data-fs-folder], [data-fs-nav="mine"]', function (e) {
      if (!dragItems) return;
      e.preventDefault();
      e.stopPropagation();
      var target = dropTarget(this), items = dragItems;
      dragItems = null;
      $('.drop-hover').removeClass('drop-hover');
      if (!target) return;
      M4W.post('api/files/move', { items: payload(items), target: target }).done(function (r) {
        clearSelection(true); refresh(); M4W.toast(t('fs_moved', { n: r.count }), 'success');
      });
    });

  // ------------------------------------------------------------- list events
  $('#fs-body').on('click', '[data-key]', function (e) {
    if ($(e.target).closest('.fs-more, .fs-check').length) return;
    var i = +$(this).data('i'), it = itemAt(i);
    if ($(e.target).closest('[data-open]').length) {
      e.preventDefault();
      if (!e.ctrlKey && !e.metaKey && !e.shiftKey) { open(it); return; }
    }
    if (e.shiftKey && S.lastIndex >= 0) {
      var a = Math.min(S.lastIndex, i), b = Math.max(S.lastIndex, i), list = sorted();
      for (var k = a; k <= b; k++) toggle(list[k], true);
    } else if (e.ctrlKey || e.metaKey) {
      toggle(it);
    } else {
      clearSelection(true); toggle(it, true);
    }
    S.lastIndex = i;
    updateSelection();
  }).on('dblclick', '[data-key]', function (e) {
    if ($(e.target).closest('.fs-more, .fs-check').length) return;
    open(itemAt(+$(this).data('i')));
  }).on('change', '.fs-check', function () {
    var $r = $(this).closest('[data-key]'), i = +$r.data('i');
    toggle(itemAt(i), this.checked); S.lastIndex = i; updateSelection();
  }).on('change', '#fs-check-all', function () {
    var on = this.checked; S.items.forEach(function (it) { toggle(it, on); }); updateSelection();
  }).on('click', '.fs-more', function (e) {
    e.stopPropagation();
    var it = itemAt(+$(this).closest('[data-key]').data('i'));
    var items = S.selected[itemKey(it)] ? selectedItems() : [it];
    var r = this.getBoundingClientRect();
    showMenu(r.left - 180, r.bottom + 4, items);
  }).on('contextmenu', '[data-key]', function (e) {
    e.preventDefault();
    var it = itemAt(+$(this).data('i'));
    if (!S.selected[itemKey(it)]) { clearSelection(true); toggle(it, true); updateSelection(); }
    showMenu(e.clientX, e.clientY, selectedItems());
  }).on('keydown', '[data-key]', function (e) {
    var i = +$(this).data('i');
    if (e.key === 'Enter') { e.preventDefault(); open(itemAt(i)); }
    if (e.key === ' ') { e.preventDefault(); toggle(itemAt(i)); updateSelection(); }
  }).on('click', '.fs-sort', function () {
    var k = $(this).data('sort');
    if (S.sort === k) S.dir = -S.dir; else { S.sort = k; S.dir = k === 'name' ? 1 : -1; }
    render();
  });

  $(document).on('click', '[data-fs]', function (e) {
    var a = $(this).data('fs');
    if (actions[a]) { e.preventDefault(); actions[a](); }
  });
  $(document).on('click', '[data-fs-view]', function () {
    S.view = $(this).data('fs-view');
    try { localStorage.setItem('m4w.fs.view', S.view); } catch (e) { /* ignore */ }
    render();
  });

  $('#fs-search').on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var q = String($(this).val()).trim();
      window.location.hash = q.length >= 2 ? 'search/' + encodeURIComponent(q) : 'mine';
    }
    if (e.key === 'Escape') { $(this).val(''); if (S.route === 'search') window.history.back(); }
  });

  // Keyboard shortcuts (outside inputs and dialogs).
  $(document).on('keydown', function (e) {
    if ($(e.target).is('input, textarea, select, [contenteditable]') || $('.modal.show').length) return;
    if (e.key === 'Delete' && selectedItems().length) { e.preventDefault(); if (S.route === 'trash') actions.purge(); else if (selectedItems().every(itemWritable)) actions.delete(); }
    if (e.key === 'F2' && selectedItems().length === 1 && itemWritable(selectedItems()[0])) { e.preventDefault(); actions.rename(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a' && S.items.length) { e.preventDefault(); S.items.forEach(function (it) { toggle(it, true); }); updateSelection(); }
    if (e.key === 'Escape' && selectedItems().length) clearSelection();
    if (e.key === 'Backspace' && S.route === 'folder' && S.path && S.path.length > 1) { e.preventDefault(); window.location.hash = 'f/' + S.path[S.path.length - 2].id; }
  });

  $(window).on('hashchange', go);
  loadRoots().done(go);
})(jQuery, window, document);
