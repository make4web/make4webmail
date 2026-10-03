/*! Make4Web Mail — webmail single page application */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;

  var boot = {};
  try { boot = JSON.parse(document.getElementById('m4w-boot').textContent || '{}'); } catch (e) { boot = {}; }

  var S = M4W.state = {
    user: null, prefs: boot.prefs || {}, folders: [], byId: {}, byRole: {},
    folder: null, q: '', filter: '', sort: 'date', dir: 'desc', offset: 0,
    items: [], total: 0, selected: {}, activeId: null, cursor: -1, lastClicked: null,
    current: null, maxId: 0, identities: [], signature: '', loadingList: null
  };
  var $list, $scroll, $reader, $main;

  // ------------------------------------------------------------------ boot
  $(function () {
    if (!$('#m4w-app').length) return;
    $list = $('#m4w-list'); $scroll = $('#m4w-list-scroll'); $reader = $('#m4w-reader'); $main = $('#m4w-main');
    renderSkeleton();
    M4W.get('api/bootstrap').done(function (r) {
      S.user = r.user; S.prefs = r.user.prefs; S.identities = r.identities; S.signature = r.signature; S.settings = r.settings;
      setFolders(r.folders);
      renderQuota();
      if (r.vacation) {
        $('#m4w-vacation-flag').html('<a class="m4w-vacation-banner" href="' + M4W.url('settings/vacation') + '"><i class="bi bi-airplane-fill"></i><span>' + esc(t('vacation_on')) + '</span></a>');
      }
      S.maxId = 0;
      route();
      poll(true);
      setInterval(function () { if (!document.hidden) poll(false); }, 30000);
      if (boot.compose && M4W.Compose) M4W.Compose.open({ mode: 'new', to: boot.compose.to, subject: boot.compose.subject });
      if ('Notification' in window && S.prefs.notifications && Notification.permission === 'default') {
        $(document).one('click', function () { try { Notification.requestPermission(); } catch (e) {} });
      }
    });
    bindUi();
    bindKeyboard();
    $(window).on('hashchange', route);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(false); });
  });

  // ---------------------------------------------------------------- router
  function route() {
    var h = decodeURIComponent((location.hash || '').replace(/^#\/?/, ''));
    var m;
    if ((m = h.match(/^q\/(.*)$/))) {
      S.q = m[1]; S.folder = null; $('#m4w-search').val(S.q).trigger('input');
      S.offset = 0; clearSelection(); loadList(); closeReader(true); highlightFolder();
      return;
    }
    if ((m = h.match(/^f\/(\d+)(?:\/m\/(\d+))?/))) {
      var fid = +m[1], mid = m[2] ? +m[2] : null;
      if (fid !== S.folder || S.q) { S.folder = fid; S.q = ''; S.offset = 0; $('#m4w-search').val('').trigger('input'); clearSelection(); loadList(); highlightFolder(); }
      if (mid) { if (mid !== S.activeId) openMessage(mid); } else { closeReader(true); }
      return;
    }
    var inbox = S.byRole.inbox;
    if (boot.q && !S._usedBootQ) { S._usedBootQ = true; location.replace('#q/' + encodeURIComponent(boot.q)); return; }
    if (inbox) location.replace('#f/' + inbox.id);
  }
  function go(folderId, msgId) { location.hash = 'f/' + folderId + (msgId ? '/m/' + msgId : ''); }
  M4W.go = go;

  // --------------------------------------------------------------- folders
  function setFolders(list) {
    S.folders = list; S.byId = {}; S.byRole = {};
    list.forEach(function (f) { S.byId[f.id] = f; if (f.role) S.byRole[f.role] = f; });
    renderFolders();
    updateTitle();
  }
  M4W.setFolders = setFolders;

  function folderItem(f, depth) {
    var count = f.role === 'drafts' ? f.total : f.unread;
    var showCount = count > 0 && f.role !== 'sent' && f.role !== 'trash' && (f.role !== 'archive');
    var icon = f.color ? '<span class="m4w-dot" style="background:' + esc(f.color) + '"></span>' : '<i class="bi bi-' + esc(f.icon) + (f.role === 'inbox' && f.unread ? '-fill' : '') + '"></i>';
    var html = '<li class="m4w-nav-li"><a class="m4w-nav-item' + (f.unread && f.role !== 'drafts' ? ' has-unread' : '') + '" href="#f/' + f.id + '" data-folder="' + f.id + '" style="' + (depth ? 'padding-left:' + (1.1 + depth * 1.1) + 'rem' : '') + '">'
      + icon + '<span class="m4w-nav-label">' + esc(f.name) + '</span>'
      + (showCount ? '<span class="m4w-count">' + count + '</span>' : '') + '</a>'
      + '<div class="dropdown m4w-folder-dd" data-folder-id="' + f.id + '"><button class="btn btn-ghost btn-icon btn-sm m4w-folder-menu" data-bs-toggle="dropdown" aria-label="' + esc(t('folder_actions')) + '"><i class="bi bi-three-dots-vertical"></i></button>'
      + '<div class="dropdown-menu dropdown-menu-end">'
      + '<button class="dropdown-item" data-folder-act="read-all"><i class="bi bi-check2-all"></i>' + esc(t('mark_all_read')) + '</button>'
      + (!f.role ? '<button class="dropdown-item" data-folder-act="new-sub"><i class="bi bi-folder-plus"></i>' + esc(t('new_subfolder')) + '</button>'
        + '<button class="dropdown-item" data-folder-act="rename"><i class="bi bi-pencil"></i>' + esc(t('rename')) + '</button>'
        + '<div class="dropdown-divider"></div><button class="dropdown-item text-danger" data-folder-act="delete"><i class="bi bi-trash3 text-danger"></i>' + esc(t('delete_folder')) + '</button>' : '')
      + (f.role === 'trash' || f.role === 'spam' ? '<button class="dropdown-item text-danger" data-folder-act="empty"><i class="bi bi-trash3 text-danger"></i>' + esc(t('empty_folder')) + '</button>' : '')
      + (f.role === 'inbox' ? '<button class="dropdown-item" data-folder-act="rules"><i class="bi bi-funnel"></i>' + esc(t('manage_rules')) + '</button>' : '')
      + '</div></div></li>';
    return html;
  }

  function renderFolders() {
    var order = ['inbox', 'flagged', 'drafts', 'sent', 'archive', 'spam', 'trash'];
    var sys = S.folders.filter(function (f) { return f.role; }).sort(function (a, b) { return order.indexOf(a.role) - order.indexOf(b.role); });
    var html = '';
    sys.forEach(function (f) {
      html += folderItem(f, 0);
      if (f.role === 'inbox') {
        html += '<li class="m4w-nav-li"><a class="m4w-nav-item" href="#q/is:starred" data-search="is:starred"><i class="bi bi-star"></i><span class="m4w-nav-label">' + esc(t('starred')) + '</span></a></li>';
      }
    });
    $('#m4w-folders').html(html);
    var custom = S.folders.filter(function (f) { return !f.role; });
    var children = {};
    custom.forEach(function (f) { var p = f.parent_id && S.byId[f.parent_id] ? f.parent_id : 0; (children[p] = children[p] || []).push(f); });
    var out = '';
    (function walk(pid, depth) {
      (children[pid] || []).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (f) { out += folderItem(f, depth); walk(f.id, depth + 1); });
    })(0, 0);
    $('#m4w-user-folders').html(out || '<li class="px-3 small text-muted" style="padding-left:1.1rem!important">' + esc(t('no_folders')) + '</li>');
    highlightFolder();
    renderMoveMenus();
  }

  function highlightFolder() {
    $('.m4w-sidebar .m4w-nav-item').removeClass('active');
    if (S.q === 'is:starred') $('[data-search="is:starred"]').addClass('active');
    else if (S.folder && !S.q) $('.m4w-nav-item[data-folder="' + S.folder + '"]').addClass('active');
    var f = S.byId[S.folder];
    $('#m4w-folder-title').text(S.q ? (S.q === 'is:starred' ? t('starred') : t('search_results', { q: S.q })) : (f ? f.name : ''));
    $('[data-action=empty-folder]').toggleClass('d-none', !(f && !S.q && (f.role === 'trash' || f.role === 'spam')));
  }

  function renderMoveMenus() {
    var html = '<h6 class="dropdown-header">' + esc(t('move_to')) + '</h6>';
    S.folders.forEach(function (f) {
      if (f.role === 'drafts' || f.role === 'sent') return;
      html += '<button class="dropdown-item" data-move-to="' + f.id + '"><i class="bi bi-' + esc(f.icon) + '"></i>' + esc(f.name) + '</button>';
    });
    html += '<div class="dropdown-divider"></div><button class="dropdown-item" data-action="new-folder-move"><i class="bi bi-folder-plus"></i>' + esc(t('new_folder')) + '</button>';
    $('[data-move-menu]').html(html);
  }

  function renderQuota() {
    var u = S.user;
    if (!u) return;
    var used = M4W.bytes(u.used_bytes);
    if (u.quota_mb > 0) {
      var pct = Math.min(100, Math.round(u.used_bytes / (u.quota_mb * 1048576) * 100));
      $('#m4w-quota').html('<div class="progress"><div class="progress-bar ' + (pct > 90 ? 'bg-danger' : (pct > 75 ? 'bg-warning' : '')) + '" style="width:' + Math.max(pct, 1) + '%"></div></div>'
        + esc(t('quota_used', { used: used, total: M4W.bytes(u.quota_mb * 1048576), pct: pct })));
    } else {
      $('#m4w-quota').html('<i class="bi bi-hdd"></i> ' + esc(t('quota_unlimited', { used: used })));
    }
  }

  function updateTitle() {
    var inbox = S.byRole.inbox;
    var n = inbox ? inbox.unread : 0;
    var brand = document.title.split(' · ').pop();
    var f = S.byId[S.folder];
    document.title = (n ? '(' + n + ') ' : '') + (f ? f.name + ' · ' : '') + brand;
  }

  // ---------------------------------------------------------------- list
  function renderSkeleton() {
    var h = '';
    for (var i = 0; i < 9; i++) h += '<div class="m4w-skeleton"><i style="width:36px;height:36px;border-radius:50%"></i><div class="flex-grow-1"><i style="width:' + (30 + (i * 17) % 40) + '%;margin-bottom:8px"></i><i style="width:' + (55 + (i * 13) % 35) + '%"></i></div></div>';
    $('#m4w-list-scroll').html(h);
  }

  function loadList(silent) {
    if (S.loadingList) S.loadingList.abort && S.loadingList.abort();
    if (!silent) renderSkeleton();
    var params = { folder: S.folder || 0, q: S.q, filter: S.filter, offset: S.offset, sort: S.sort, dir: S.dir, threads: S.prefs.conversations && !S.q ? 1 : 0, limit: S.prefs.page_size || 50 };
    var req = M4W.get('api/messages', params, { silent: !!silent, quiet: true });
    S.loadingList = req;
    return req.done(function (r) {
      S.items = r.items; S.total = r.total;
      renderList();
    });
  }
  M4W.reloadList = function () { return loadList(true); };

  function isSentLike() {
    var f = S.byId[S.folder];
    return f && (f.role === 'sent' || f.role === 'drafts');
  }

  function rowHtml(m) {
    var sent = isSentLike();
    var who, whoEmail;
    if (sent || m.draft) {
      var to = (m.to || [])[0] || {};
      who = (m.to || []).map(function (a) { return a.name || a.email; }).join(', ') || t('no_recipient');
      whoEmail = to.email || '';
    } else {
      who = m.from_name || m.from_email || t('unknown_sender');
      whoEmail = m.from_email;
    }
    var icons = '';
    if (m.priority < 3) icons += '<i class="bi bi-exclamation-circle-fill" title="' + esc(t('high_priority')) + '"></i>';
    if (m.attachments) icons += '<i class="bi bi-paperclip" title="' + esc(t('has_attachments')) + '"></i>';
    if (m.answered) icons += '<i class="bi bi-reply" title="' + esc(t('answered')) + '"></i>';
    if (m.forwarded) icons += '<i class="bi bi-forward" title="' + esc(t('forwarded')) + '"></i>';
    var folderChip = (S.q && S.byId[m.folder_id] && S.byId[m.folder_id].role !== 'inbox') ? '<span class="m4w-chip-folder">' + esc(S.byId[m.folder_id].name) + '</span>' : '';
    return '<div class="m4w-row' + (m.unread ? ' unread' : '') + (S.selected[m.id] ? ' checked' : '') + (S.activeId === m.id ? ' active' : '') + '" data-id="' + m.id + '" draggable="true"' + (S.activeId === m.id ? ' aria-current="true"' : '') + '>'
      + '<input type="checkbox" class="form-check-input m4w-check" ' + (S.selected[m.id] ? 'checked' : '') + ' aria-label="' + esc(t('select')) + '">'
      + '<button type="button" class="m4w-star' + (m.flagged ? ' on' : '') + '" aria-label="' + esc(t('star')) + '"><i class="bi bi-star' + (m.flagged ? '-fill' : '') + '"></i></button>'
      + M4W.avatar(who, whoEmail)
      + '<div class="m4w-row-main"><div class="m4w-row-line1">'
      + (m.scheduled ? '<span class="badge badge-soft me-1" title="' + esc(M4W.date(m.scheduled, true)) + '"><i class="bi bi-clock"></i> ' + esc(new Date(m.scheduled * 1000).toLocaleString(document.documentElement.lang, { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })) + '</span>' : (m.draft ? '<span class="text-danger fw-semibold small me-1">' + esc(t('draft')) + '</span>' : ''))
      + (sent && !m.draft ? '<span class="text-muted small">' + esc(t('to_prefix')) + '</span>' : '')
      + '<span class="m4w-row-from">' + esc(who) + '</span>'
      + (m.thread_count > 1 ? '<span class="m4w-row-count">' + m.thread_count + '</span>' : '')
      + '</div><div class="m4w-row-subject">' + folderChip + ' ' + esc(m.subject || t('no_subject')) + '</div>'
      + '<div class="m4w-row-snippet">' + esc(m.snippet || '') + '</div></div>'
      + '<div class="m4w-row-meta"><span class="m4w-row-date" title="' + esc(M4W.date(m.date, true)) + '">' + esc(M4W.date(m.date)) + '</span><span class="m4w-row-icons">' + icons + '</span></div>'
      + '<div class="m4w-row-hover">'
      + '<button class="btn btn-ghost btn-icon" data-row-act="archive" title="' + esc(t('archive')) + '"><i class="bi bi-archive"></i></button>'
      + '<button class="btn btn-ghost btn-icon" data-row-act="delete" title="' + esc(t('delete')) + '"><i class="bi bi-trash3"></i></button>'
      + '<button class="btn btn-ghost btn-icon" data-row-act="' + (m.unread ? 'read' : 'unread') + '" title="' + esc(m.unread ? t('mark_read') : t('mark_unread')) + '"><i class="bi bi-envelope' + (m.unread ? '-open' : '') + '"></i></button>'
      + '</div></div>';
  }

  function renderList() {
    if (!S.items.length) {
      var f = S.byId[S.folder] || {};
      var icon = S.q ? 'search' : ({ inbox: 'inbox', sent: 'send', drafts: 'file-earmark-text', spam: 'shield-check', trash: 'trash3', archive: 'archive' }[f.role] || 'folder2-open');
      var title = S.q ? t('empty_search') : (f.role === 'inbox' && !S.filter ? t('empty_inbox') : t('empty_folder_title'));
      var hint = S.q ? t('empty_search_hint') : (f.role === 'inbox' && !S.filter ? t('empty_inbox_hint') : '');
      $scroll.html('<div class="m4w-empty" data-aos="fade-up"><div class="m4w-empty-icon"><i class="bi bi-' + icon + '"></i></div><h3>' + esc(title) + '</h3><p class="small">' + esc(hint) + '</p></div>');
      $('#m4w-pager').html('');
      syncSelectionUi();
      return;
    }
    var html = '', lastGroup = null;
    S.items.forEach(function (m) {
      if (S.sort === 'date') {
        var g = M4W.dayGroup(m.date);
        if (g !== lastGroup) { html += '<div class="m4w-day-sep">' + esc(g) + '</div>'; lastGroup = g; }
      }
      html += rowHtml(m);
    });
    $scroll.html(html);
    var from = S.offset + 1, to = S.offset + S.items.length;
    $('#m4w-pager').html('<span>' + esc(t('pager', { from: from, to: to, total: S.total })) + '</span><span class="d-flex gap-1">'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-page="-1" ' + (S.offset <= 0 ? 'disabled' : '') + ' aria-label="' + esc(t('prev')) + '"><i class="bi bi-chevron-left"></i></button>'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-page="1" ' + (to >= S.total ? 'disabled' : '') + ' aria-label="' + esc(t('next')) + '"><i class="bi bi-chevron-right"></i></button></span>');
    syncSelectionUi();
  }

  function rowById(id) { return $scroll.find('.m4w-row[data-id="' + id + '"]'); }
  function itemById(id) { for (var i = 0; i < S.items.length; i++) if (S.items[i].id === +id) return S.items[i]; return null; }

  // ------------------------------------------------------------ selection
  function selectedIds() { return Object.keys(S.selected).map(Number); }
  function clearSelection() { S.selected = {}; syncSelectionUi(); }
  function toggleSelect(id, on) {
    if (on === undefined) on = !S.selected[id];
    if (on) S.selected[id] = true; else delete S.selected[id];
    rowById(id).toggleClass('checked', on).find('.m4w-check').prop('checked', on);
    syncSelectionUi();
  }
  function syncSelectionUi() {
    var n = selectedIds().length;
    $list.toggleClass('has-selection', n > 0);
    $('#m4w-sel-count').text(n ? t('selected_count', { n: n }) : '');
    var all = S.items.length && n === S.items.length;
    $('#m4w-select-all').prop('checked', !!all).prop('indeterminate', n > 0 && !all);
  }

  // --------------------------------------------------------------- reader
  function openMessage(id) {
    var item = itemById(id);
    if (item && item.draft && M4W.Compose) {
      M4W.Compose.open({ mode: 'draft', id: id });
      var f = S.folder; if (f) history.replaceState(null, '', '#f/' + f);
      return;
    }
    S.activeId = id;
    $scroll.find('.m4w-row').removeClass('active').removeAttr('aria-current');
    rowById(id).addClass('active').attr('aria-current', 'true');
    S.cursor = S.items.findIndex(function (m) { return m.id === id; });
    $main.addClass('reading');
    $reader.addClass('is-open');
    $('#m4w-reader-empty').addClass('d-none');
    var $c = $('#m4w-reader-content').removeClass('d-none').addClass('d-flex').html('<div class="m4w-reader-toolbar"></div><div class="m4w-reader-scroll"><div class="m4w-skeleton border-0"><i style="width:60%;height:18px"></i></div><div class="m4w-skeleton border-0"><i style="width:36px;height:36px;border-radius:50%"></i><div class="flex-grow-1"><i style="width:40%;margin-bottom:8px"></i><i style="width:25%"></i></div></div></div>');
    var threads = S.prefs.conversations && !isSentLike() ? 1 : 0;
    M4W.get('api/messages/' + id, { thread: threads }).done(function (r) {
      if (S.activeId !== id) return;
      S.current = r;
      renderReader(r);
      var delay = S.prefs.mark_read_delay === undefined ? 1 : +S.prefs.mark_read_delay;
      if (r.unread_ids.length && delay >= 0) {
        clearTimeout(S.readTimer);
        S.readTimer = setTimeout(function () {
          if (S.activeId !== id) return;
          action('read', r.unread_ids, { silent: true, noReload: true });
          var it = itemById(id); if (it) { it.unread = false; rowById(id).removeClass('unread'); }
        }, delay * 1000);
      }
    }).fail(function () { closeReader(); });
  }
  M4W.openMessage = openMessage;

  function closeReader(keepHash) {
    S.activeId = null; S.current = null;
    $main.removeClass('reading');
    $reader.removeClass('is-open');
    $scroll.find('.m4w-row').removeClass('active');
    $('#m4w-reader-content').addClass('d-none').removeClass('d-flex').empty();
    $('#m4w-reader-empty').removeClass('d-none');
    if (!keepHash && S.folder) history.replaceState(null, '', '#f/' + S.folder);
  }

  function addrList(list) {
    return (list || []).map(function (a) { return '<span title="' + esc(a.email) + '">' + esc(a.name || a.email) + '</span>'; }).join(', ');
  }

  function attachmentsHtml(msg) {
    var atts = (msg.attachments || []).filter(function (a) { return !a.inline; });
    if (!atts.length) return '';
    var total = 0;
    var html = atts.map(function (a) {
      total += a.size;
      var ic = M4W.fileIcon(a.name, a.mime);
      var inlineUrl = M4W.url('api/messages/' + msg.id + '/part/' + encodeURIComponent(a.part), { inline: 1 });
      var dlUrl = M4W.url('api/messages/' + msg.id + '/part/' + encodeURIComponent(a.part));
      var thumb = ic[0] === 'img' ? '<img src="' + esc(inlineUrl) + '" alt="" loading="lazy">' : '<i class="bi bi-' + ic[1] + '"></i>';
      var previewable = /^image\/(png|jpe?g|gif|webp)$/.test(a.mime) || a.mime === 'application/pdf' || a.mime === 'text/plain';
      return '<div class="m4w-att" title="' + esc(a.name) + '"><a class="m4w-att-icon ' + ic[0] + '" href="' + esc(previewable ? inlineUrl : dlUrl) + '" target="_blank" rel="noopener">' + thumb + '</a>'
        + '<a class="min-w-0 flex-grow-1 text-reset" href="' + esc(previewable ? inlineUrl : dlUrl) + '" target="_blank" rel="noopener"><div class="m4w-att-name">' + esc(a.name) + '</div><div class="m4w-att-size">' + esc(M4W.bytes(a.size)) + '</div></a>'
        + '<a class="btn btn-ghost btn-icon btn-sm" href="' + esc(dlUrl) + '" title="' + esc(t('download')) + '" download><i class="bi bi-download"></i></a></div>';
    }).join('');
    var zip = atts.length > 1 ? ' · <a href="' + esc(M4W.url('api/messages/' + msg.id + '/zip')) + '"><i class="bi bi-file-zip"></i> ' + esc(t('download_all')) + '</a>' : '';
    return '<div class="m4w-msg-atts"><div class="w-100 small text-muted mb-1"><i class="bi bi-paperclip"></i> ' + esc(t('n_attachments', { n: atts.length, size: M4W.bytes(total) })) + zip + '</div>' + html + '</div>';
  }

  function messageCard(msg, expanded) {
    var from = msg.from || {};
    var rcpt = '<div class="m4w-msg-recipients"><span class="dropdown"><a href="#" class="dropdown-toggle text-muted" data-bs-toggle="dropdown">' + esc(t('to_prefix')) + ' ' + addrList(msg.to.slice(0, 3)) + (msg.to.length > 3 ? '…' : '') + (msg.cc.length ? ', ' + addrList(msg.cc.slice(0, 2)) : '') + '</a>'
      + '<div class="dropdown-menu p-3 small" style="min-width:320px;max-width:520px">'
      + '<table class="w-100"><tr><td class="text-muted pe-2 align-top">' + esc(t('from')) + '</td><td><b>' + esc(from.name) + '</b> &lt;' + esc(from.email) + '&gt;</td></tr>'
      + '<tr><td class="text-muted pe-2 align-top">' + esc(t('to')) + '</td><td>' + msg.to.map(function (a) { return esc(a.name ? a.name + ' <' + a.email + '>' : a.email); }).join('<br>') + '</td></tr>'
      + (msg.cc.length ? '<tr><td class="text-muted pe-2 align-top">Cc</td><td>' + msg.cc.map(function (a) { return esc(a.name ? a.name + ' <' + a.email + '>' : a.email); }).join('<br>') + '</td></tr>' : '')
      + (msg.bcc.length ? '<tr><td class="text-muted pe-2 align-top">Cci</td><td>' + msg.bcc.map(function (a) { return esc(a.email); }).join('<br>') + '</td></tr>' : '')
      + '<tr><td class="text-muted pe-2">' + esc(t('date')) + '</td><td>' + esc(M4W.date(msg.date, true)) + '</td></tr>'
      + '<tr><td class="text-muted pe-2">' + esc(t('size')) + '</td><td>' + esc(M4W.bytes(msg.size)) + '</td></tr>'
      + '</table></div></span></div>';
    var banner = '';
    if (msg.blocked_images > 0) {
      banner = '<div class="m4w-msg-banner"><i class="bi bi-shield-lock text-primary"></i><span>' + esc(t('images_blocked', { n: msg.blocked_images })) + '</span>'
        + '<a href="#" data-show-images="' + msg.id + '" class="fw-semibold">' + esc(t('show_images')) + '</a> · <a href="#" data-always-images class="text-muted">' + esc(t('always_show_images')) + '</a></div>';
    }
    if (msg.unsubscribe) {
      banner += '<div class="m4w-msg-banner"><i class="bi bi-envelope-slash text-muted"></i><span>' + esc(t('newsletter_hint')) + '</span><a href="#" data-unsubscribe="' + msg.id + '" class="fw-semibold">' + esc(t('unsubscribe')) + '</a></div>';
    }
    return '<article class="m4w-msg' + (expanded ? '' : ' collapsed') + '" data-msg="' + msg.id + '">'
      + '<div class="m4w-msg-head" data-toggle-msg>' + M4W.avatar(from.name || from.email, from.email, 'lg')
      + '<div class="m4w-msg-who"><div><span class="m4w-msg-name">' + esc(from.name || from.email) + '</span> <span class="m4w-msg-email">&lt;' + esc(from.email) + '&gt;</span></div>'
      + rcpt + '<div class="m4w-msg-snippet">' + esc(msg.snippet) + '</div></div>'
      + '<div class="d-flex align-items-start gap-1"><div class="m4w-msg-date pt-1">' + (msg.attachments.some(function (a) { return !a.inline; }) ? '<i class="bi bi-paperclip me-1"></i>' : '') + esc(M4W.date(msg.date)) + '<div class="small-2 d-none d-md-block">' + esc(M4W.relative(msg.date)) + '</div></div>'
      + '<span class="m4w-msg-actions-inline d-flex"><button class="btn btn-ghost btn-icon btn-sm" data-msg-act="star" title="' + esc(t('star')) + '"><i class="bi bi-star' + (msg.flagged ? '-fill text-warning' : '') + '"></i></button>'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-msg-act="reply" title="' + esc(t('reply')) + '"><i class="bi bi-reply"></i></button>'
      + '<span class="dropdown"><button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="' + esc(t('more_actions')) + '"><i class="bi bi-three-dots-vertical"></i></button><span class="dropdown-menu dropdown-menu-end">'
      + '<button class="dropdown-item" data-msg-act="reply"><i class="bi bi-reply"></i>' + esc(t('reply')) + '</button>'
      + '<button class="dropdown-item" data-msg-act="reply_all"><i class="bi bi-reply-all"></i>' + esc(t('reply_all')) + '</button>'
      + '<button class="dropdown-item" data-msg-act="forward"><i class="bi bi-forward"></i>' + esc(t('forward')) + '</button>'
      + '<button class="dropdown-item" data-msg-act="forward_att"><i class="bi bi-paperclip"></i>' + esc(t('forward_attachment')) + '</button>'
      + '<div class="dropdown-divider"></div>'
      + '<button class="dropdown-item" data-msg-act="edit"><i class="bi bi-pencil-square"></i>' + esc(t('edit_as_new')) + '</button>'
      + '<button class="dropdown-item" data-msg-act="unread"><i class="bi bi-envelope"></i>' + esc(t('mark_unread_from_here')) + '</button>'
      + '<a class="dropdown-item" target="_blank" href="' + M4W.url('api/messages/' + msg.id + '/print') + '"><i class="bi bi-printer"></i>' + esc(t('print')) + '</a>'
      + '<a class="dropdown-item" target="_blank" href="' + M4W.url('api/messages/' + msg.id + '/source') + '"><i class="bi bi-code-slash"></i>' + esc(t('view_source')) + '</a>'
      + '<a class="dropdown-item" href="' + M4W.url('api/messages/' + msg.id + '/download') + '"><i class="bi bi-download"></i>' + esc(t('download_eml')) + '</a>'
      + '<button class="dropdown-item" data-msg-act="rule"><i class="bi bi-funnel"></i>' + esc(t('filter_like_this')) + '</button>'
      + '<div class="dropdown-divider"></div>'
      + '<button class="dropdown-item text-danger" data-msg-act="delete"><i class="bi bi-trash3 text-danger"></i>' + esc(t('delete_this')) + '</button>'
      + '</span></span></span></div></div>'
      + banner
      + '<div class="m4w-msg-body">' + (expanded ? iframeHtml(msg.id) : '') + '</div>'
      + attachmentsHtml(msg)
      + '</article>';
  }

  function iframeHtml(id, images) {
    var src = M4W.url('api/messages/' + id + '/body', images ? { images: 1 } : null);
    return '<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="lazy" title="' + esc(t('message_content')) + '" src="' + esc(src) + '"></iframe>';
  }

  function fitIframe(frame) {
    try {
      var doc = frame.contentDocument;
      if (!doc) return;
      var h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
      frame.style.height = (h + 4) + 'px';
    } catch (e) { frame.style.height = '600px'; }
  }

  function bindIframes($ctx) {
    $ctx.find('iframe').each(function () {
      var frame = this;
      $(frame).off('load.m4w').on('load.m4w', function () {
        fitIframe(frame);
        try {
          var doc = frame.contentDocument;
          $(doc).find('img').on('load', function () { fitIframe(frame); });
          if (window.ResizeObserver && doc.body) new ResizeObserver(function () { fitIframe(frame); }).observe(doc.body);
          // Keyboard shortcuts keep working when focus is inside the message.
          doc.addEventListener('keydown', function (e) { $(document).trigger($.Event('keydown', { key: e.key, ctrlKey: e.ctrlKey, metaKey: e.metaKey, shiftKey: e.shiftKey, altKey: e.altKey, target: document.body })); });
        } catch (e) {}
      });
    });
  }

  function renderReader(r) {
    var msg = r.message, folder = r.folder || {};
    var thread = r.thread && r.thread.length ? r.thread : [msg];
    var last = thread[thread.length - 1];
    var inTrash = folder.role === 'trash', inSpam = folder.role === 'spam';
    var pos = S.cursor >= 0 ? t('position', { n: S.offset + S.cursor + 1, total: S.total }) : '';
    var tb = '<button class="btn btn-ghost btn-icon" data-reader="back" title="' + esc(t('back')) + ' (U)"><i class="bi bi-arrow-left"></i></button>'
      + (folder.role !== 'archive' ? '<button class="btn btn-ghost btn-icon" data-reader="archive" title="' + esc(t('archive')) + ' (E)"><i class="bi bi-archive"></i></button>' : '<button class="btn btn-ghost btn-icon" data-reader="inbox" title="' + esc(t('move_inbox')) + '"><i class="bi bi-inbox"></i></button>')
      + (inSpam ? '<button class="btn btn-ghost btn-icon" data-reader="inbox" title="' + esc(t('not_spam')) + '"><i class="bi bi-shield-check"></i></button>' : '<button class="btn btn-ghost btn-icon" data-reader="spam" title="' + esc(t('spam')) + ' (!)"><i class="bi bi-exclamation-octagon"></i></button>')
      + '<button class="btn btn-ghost btn-icon" data-reader="delete" title="' + esc(inTrash ? t('delete_forever') : t('delete')) + ' (#)"><i class="bi bi-trash3"></i></button>'
      + '<span class="vr"></span>'
      + '<button class="btn btn-ghost btn-icon" data-reader="unread" title="' + esc(t('mark_unread')) + ' (Shift+U)"><i class="bi bi-envelope"></i></button>'
      + '<span class="dropdown"><button class="btn btn-ghost btn-icon" data-bs-toggle="dropdown" title="' + esc(t('move')) + ' (V)"><i class="bi bi-folder-symlink"></i></button><div class="dropdown-menu" data-move-menu data-move-current></div></span>'
      + '<button class="btn btn-ghost btn-icon" data-reader="star" title="' + esc(t('star')) + ' (S)"><i class="bi bi-star' + (msg.flagged ? '-fill text-warning' : '') + '"></i></button>'
      + '<span class="ms-auto d-flex align-items-center gap-1"><span class="small text-muted d-none d-xl-inline me-1">' + esc(pos) + '</span>'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-reader="prev" title="' + esc(t('newer')) + ' (K)"><i class="bi bi-chevron-left"></i></button>'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-reader="next" title="' + esc(t('older')) + ' (J)"><i class="bi bi-chevron-right"></i></button>'
      + '<a class="btn btn-ghost btn-icon btn-sm m4w-desktop-only" target="_blank" href="' + M4W.url('api/messages/' + last.id + '/print') + '" title="' + esc(t('print')) + '"><i class="bi bi-printer"></i></a></span>';

    var subjBadges = '';
    if (folder.name && folder.role !== 'inbox') subjBadges += '<span class="badge badge-soft-secondary">' + esc(folder.name) + '</span>';
    if (msg.priority < 3) subjBadges += '<span class="badge badge-soft-danger"><i class="bi bi-exclamation-circle"></i> ' + esc(t('high_priority')) + '</span>';
    if (inSpam) subjBadges += '<span class="badge badge-soft-warning"><i class="bi bi-exclamation-triangle"></i> ' + esc(t('spam_warning')) + '</span>';

    var body = '<h1 class="m4w-subject"><span>' + esc(msg.subject || t('no_subject')) + '</span>' + subjBadges + '</h1>';
    if (thread.length > 3) {
      // Collapse the middle of long conversations, like Gmail.
      body += messageCard(thread[0], false);
      body += '<button class="btn btn-light btn-sm w-100 mb-3 rounded-pill" data-expand-thread><i class="bi bi-chevron-expand me-1"></i>' + esc(t('n_older', { n: thread.length - 2 })) + '</button>';
      body += '<div class="d-none" data-hidden-thread>' + thread.slice(1, -1).map(function (m) { return messageCard(m, m.unread); }).join('') + '</div>';
      body += messageCard(last, true);
    } else {
      thread.forEach(function (m, i) { body += messageCard(m, i === thread.length - 1 || m.unread || thread.length === 1); });
    }
    if (!inTrash) {
      body += '<div class="m4w-quick-reply"><button class="btn btn-light" data-msg-act="reply" data-msg-id="' + last.id + '"><i class="bi bi-reply me-1"></i>' + esc(t('reply')) + '</button>'
        + ((last.to.length + last.cc.length) > 1 ? '<button class="btn btn-light" data-msg-act="reply_all" data-msg-id="' + last.id + '"><i class="bi bi-reply-all me-1"></i>' + esc(t('reply_all')) + '</button>' : '')
        + '<button class="btn btn-light" data-msg-act="forward" data-msg-id="' + last.id + '"><i class="bi bi-forward me-1"></i>' + esc(t('forward')) + '</button></div>';
    }
    var $c = $('#m4w-reader-content');
    $c.html('<div class="m4w-reader-toolbar">' + tb + '</div><div class="m4w-reader-scroll" tabindex="-1">' + body + '</div>');
    renderMoveMenus();
    bindIframes($c);
    $c.find('[data-bs-toggle=dropdown]').each(function () { bootstrap.Dropdown.getOrCreateInstance(this, { popperConfig: { strategy: 'fixed' } }); });
  }

  // ------------------------------------------------------------- actions
  function action(name, ids, opts) {
    opts = opts || {};
    ids = ids || [];
    if (!ids.length) return $.Deferred().reject().promise();
    var payload = { action: name, ids: ids, thread: opts.thread ? 1 : 0 };
    if (opts.folder) payload.folder = opts.folder;
    return M4W.post('api/messages/action', payload, { silent: opts.silent }).done(function (r) {
      setFolders(r.folders);
      if (!opts.noReload) loadList(true);
    });
  }
  M4W.action = action;

  var LABELS = { archive: 'archived', delete: 'deleted', spam: 'marked_spam', move: 'moved', inbox: 'moved_inbox', purge: 'deleted_forever' };

  function bulk(name, ids, opts) {
    opts = opts || {};
    ids = ids || selectedIds();
    if (!ids.length) return;
    var f = S.byId[S.folder] || {};
    var origin = S.folder;
    if (name === 'delete' && (f.role === 'trash' || f.role === 'spam')) {
      M4W.confirm(t('confirm_purge_title'), t('confirm_purge', { n: ids.length }), t('delete_forever')).done(function () { doIt('purge'); });
      return;
    }
    doIt(name);
    function doIt(n) {
      var removing = ['archive', 'delete', 'spam', 'move', 'inbox', 'purge'].indexOf(n) !== -1;
      var threaded = S.prefs.conversations && !S.q && !isSentLike();
      if (removing) {
        // Optimistic UI
        ids.forEach(function (id) { rowById(id).slideUp(120); });
        if (S.activeId && ids.indexOf(S.activeId) !== -1) {
          var next = S.items[S.cursor + 1] || S.items[S.cursor - 1];
          if (next && ids.indexOf(next.id) === -1 && $(window).width() >= 992 && S.prefs.reading_pane !== 'off') { go(S.folder, next.id); } else { closeReader(); }
        }
      }
      action(n, ids, { folder: opts.folder, thread: threaded }).done(function (r) {
        clearSelection();
        if (LABELS[n]) {
          var moved = r.ids || ids;
          M4W.toast(t(LABELS[n], { n: ids.length }), 'success', n === 'purge' ? {} : {
            action: { label: t('undo'), fn: function () { action('move', moved, { folder: origin }).done(function () { M4W.toast(t('undone'), 'info'); }); } }
          });
        }
        if (['read', 'unread', 'flag', 'unflag'].indexOf(n) !== -1) { ids.forEach(function (id) { var it = itemById(id); if (!it) return; if (n === 'read') it.unread = false; if (n === 'unread') it.unread = true; if (n === 'flag') it.flagged = true; if (n === 'unflag') it.flagged = false; }); }
      });
    }
  }
  M4W.bulk = bulk;

  function currentIds() {
    var sel = selectedIds();
    if (sel.length) return sel;
    return S.activeId ? [S.activeId] : [];
  }

  function toggleStar(id) {
    var it = itemById(id);
    var on = it ? !it.flagged : !(S.current && S.current.message.flagged);
    if (it) it.flagged = on;
    var $r = rowById(id).find('.m4w-star').toggleClass('on', on);
    $r.find('.bi').attr('class', 'bi bi-star' + (on ? '-fill' : ''));
    action(on ? 'flag' : 'unflag', [id], { silent: true, noReload: true });
    if (S.current && S.current.message.id === id) {
      S.current.message.flagged = on;
      $('[data-reader=star] .bi').attr('class', 'bi bi-star' + (on ? '-fill text-warning' : ''));
    }
  }

  function moveCursor(delta) {
    if (!S.items.length) return;
    var i = S.cursor + delta;
    if (i < 0 || i >= S.items.length) {
      if (delta > 0 && S.offset + S.items.length < S.total) { S.offset += S.items.length; loadList().done(function () { S.cursor = 0; if (S.activeId) go(S.folder, S.items[0].id); focusRow(); }); }
      return;
    }
    S.cursor = i;
    if (S.activeId && S.folder) go(S.folder, S.items[i].id); else focusRow();
  }
  function focusRow() {
    $scroll.find('.m4w-row').removeClass('focused').css('box-shadow', '');
    var it = S.items[S.cursor];
    if (!it) return;
    var $r = rowById(it.id).addClass('focused').css('box-shadow', 'inset 3px 0 0 var(--m4w-primary)');
    if ($r.length) $r[0].scrollIntoView({ block: 'nearest' });
  }

  // ------------------------------------------------------------- polling
  function poll(first) {
    M4W.get('api/poll', { since: S.maxId }, { silent: true, quiet: true }).done(function (r) {
      var prevInbox = S.byRole.inbox ? S.byRole.inbox.unread : 0;
      setFolders(r.folders);
      if (r.csrf) M4W.csrf(r.csrf);
      if (!first && r.new && r.new.length) {
        var n = r.new[0];
        if (S.byRole.inbox && S.folder === S.byRole.inbox.id && !S.q && S.offset === 0) loadList(true);
        M4W.toast(t('new_mail_from', { name: n.from_name || n.from_email }) + ' — ' + (n.subject || t('no_subject')), 'info', {
          action: { label: t('open'), fn: function () { go(S.byRole.inbox.id, +n.id); } }
        });
        if (S.prefs.notifications && 'Notification' in window && Notification.permission === 'granted' && document.hidden) {
          try {
            var notif = new Notification(n.from_name || n.from_email, { body: n.subject || t('no_subject'), tag: 'm4w-' + n.id, icon: $('link[rel=icon]').attr('href') });
            notif.onclick = function () { window.focus(); go(S.byRole.inbox.id, +n.id); notif.close(); };
          } catch (e) {}
        }
      } else if (!first && S.byRole.inbox && S.byRole.inbox.unread !== prevInbox && S.folder === S.byRole.inbox.id && !S.q) {
        loadList(true);
      }
      S.maxId = r.max_id;
    });
  }

  // ------------------------------------------------------------ folder ops
  function folderModal(opts) {
    var $m = $('#m4w-folder-modal'), $f = $('#m4w-folder-form');
    $m.find('[data-title]').text(opts.id ? t('rename_folder') : t('new_folder'));
    $f[0].reset();
    $f.find('[name=id]').val(opts.id || '');
    $f.find('[name=name]').val(opts.name || '');
    $f.find('[name=color]').val(opts.color || '');
    $f.find('[data-colors] button').css('outline', '').filter('[data-color="' + (opts.color || '') + '"]').css('outline', '2px solid var(--m4w-primary)');
    var $p = $f.find('[name=parent_id]').html('<option value="">— ' + esc(t('root')) + ' —</option>');
    S.folders.filter(function (f) { return !f.role && f.id !== opts.id; }).forEach(function (f) { $p.append($('<option>').val(f.id).text(f.name)); });
    $p.val(opts.parent || '');
    $f.find('[data-parent-wrap]').toggle(!opts.id);
    $f.data('after', opts.after || null);
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
    setTimeout(function () { $f.find('[name=name]').trigger('focus'); }, 300);
  }

  // ------------------------------------------------------------ bindings
  function bindUi() {
    $(document).on('click', '.m4w-nav-item[data-folder]', function (e) {
      $('body').removeClass('sidebar-open');
      var id = +$(this).data('folder');
      if (id === S.folder && !S.q) { e.preventDefault(); S.offset = 0; closeReader(); loadList(); }
    });
    $(document).on('click', '[data-search]', function () { $('body').removeClass('sidebar-open'); });
    $(document).on('click', '[data-folder-act]', function (e) {
      e.preventDefault(); e.stopPropagation();
      var id = +$(this).closest('[data-folder-id]').data('folder-id'), f = S.byId[id], act = $(this).data('folder-act');
      bootstrap.Dropdown.getOrCreateInstance($(this).closest('.dropdown').find('[data-bs-toggle]')[0]).hide();
      if (act === 'rules') { window.location.href = M4W.url('settings/rules'); return; }
      if (act === 'rename') folderModal({ id: id, name: f.name, color: f.color });
      if (act === 'new-sub') folderModal({ parent: id });
      if (act === 'read-all') M4W.post('api/folders/' + id + '/read-all').done(function (r) { setFolders(r.folders); if (id === S.folder) loadList(true); });
      if (act === 'delete') M4W.confirm(t('confirm_delete_folder', { name: f.name }), t('confirm_delete_folder_text'), t('delete')).done(function () {
        M4W.post('api/folders/' + id + '/delete').done(function (r) { setFolders(r.folders); if (S.folder === id) go(S.byRole.inbox.id); M4W.toast(t('folder_deleted'), 'success'); });
      });
      if (act === 'empty') M4W.confirm(t('confirm_empty', { name: f.name }), t('confirm_empty_text'), t('empty_folder')).done(function () {
        M4W.post('api/folders/' + id + '/empty').done(function (r) { setFolders(r.folders); if (S.folder === id) { closeReader(); loadList(); } M4W.toast(t('folder_emptied', { n: r.count }), 'success'); });
      });
    });
    $(document).on('click', '[data-action=new-folder]', function () { folderModal({}); });
    $(document).on('click', '[data-action=new-folder-move]', function () {
      var ids = currentIds();
      folderModal({ after: function (newId) { if (ids.length) bulk('move', ids, { folder: newId }); } });
    });
    $('#m4w-folder-form [data-colors]').on('click', 'button', function () {
      $(this).siblings().css('outline', ''); $(this).css('outline', '2px solid var(--m4w-primary)');
      $('#m4w-folder-form [name=color]').val($(this).data('color'));
    });
    $('#m4w-folder-form').on('submit', function (e) {
      e.preventDefault();
      var $f = $(this), id = $f.find('[name=id]').val(), after = $f.data('after');
      var data = { name: $f.find('[name=name]').val(), color: $f.find('[name=color]').val(), parent_id: $f.find('[name=parent_id]').val() };
      M4W.post(id ? 'api/folders/' + id + '/update' : 'api/folders', data).done(function (r) {
        setFolders(r.folders);
        bootstrap.Modal.getOrCreateInstance($('#m4w-folder-modal')[0]).hide();
        M4W.toast(id ? t('folder_renamed') : t('folder_created'), 'success');
        if (after && r.id) after(r.id);
      });
    });

    // list interactions
    $scroll.on('click', '.m4w-row', function (e) {
      var id = +$(this).data('id');
      if ($(e.target).closest('.m4w-check, .m4w-star, .m4w-row-hover').length) return;
      if (e.shiftKey && S.lastClicked) {
        var a = S.items.findIndex(function (m) { return m.id === S.lastClicked; }), b = S.items.findIndex(function (m) { return m.id === id; });
        for (var i = Math.min(a, b); i <= Math.max(a, b); i++) toggleSelect(S.items[i].id, true);
        return;
      }
      if (e.ctrlKey || e.metaKey) { toggleSelect(id); S.lastClicked = id; return; }
      S.lastClicked = id;
      if (S.folder) go(S.folder, id); else openSearchResult(id);
    });
    $scroll.on('click', '.m4w-check', function (e) {
      e.stopPropagation();
      var id = +$(this).closest('.m4w-row').data('id');
      if (e.shiftKey && S.lastClicked) {
        var a = S.items.findIndex(function (m) { return m.id === S.lastClicked; }), b = S.items.findIndex(function (m) { return m.id === id; });
        for (var i = Math.min(a, b); i <= Math.max(a, b); i++) toggleSelect(S.items[i].id, true);
      } else { toggleSelect(id, this.checked); }
      S.lastClicked = id;
    });
    $scroll.on('click', '.m4w-star', function (e) { e.stopPropagation(); toggleStar(+$(this).closest('.m4w-row').data('id')); });
    $scroll.on('click', '[data-row-act]', function (e) {
      e.stopPropagation();
      var id = +$(this).closest('.m4w-row').data('id'), act = $(this).data('row-act');
      if (act === 'read' || act === 'unread') {
        var it = itemById(id); if (it) it.unread = act === 'unread';
        rowById(id).replaceWith(rowHtml(it));
        action(act, [id], { thread: S.prefs.conversations && !S.q, noReload: true, silent: true });
      } else { bulk(act, [id]); }
    });
    $scroll.on('contextmenu', '.m4w-row', function (e) {
      e.preventDefault();
      var id = +$(this).data('id');
      if (!S.selected[id]) { clearSelection(); toggleSelect(id, true); }
      contextMenu(e.clientX, e.clientY);
    });
    $('#m4w-select-all').on('change', function () {
      var on = this.checked;
      S.items.forEach(function (m) { toggleSelect(m.id, on); });
    });
    $(document).on('click', '[data-select]', function () {
      var mode = $(this).data('select');
      S.items.forEach(function (m) {
        var on = mode === 'all' || (mode === 'read' && !m.unread) || (mode === 'unread' && m.unread) || (mode === 'flagged' && m.flagged);
        toggleSelect(m.id, on);
      });
    });
    $(document).on('click', '[data-bulk]', function () { bulk($(this).data('bulk')); });
    $(document).on('click', '[data-move-to]', function () {
      var ids = currentIds();
      bulk('move', ids, { folder: +$(this).data('move-to') });
    });
    $('#m4w-filters').on('click', 'button', function () {
      $(this).addClass('active').siblings().removeClass('active');
      S.filter = $(this).data('filter') || ''; S.offset = 0; loadList();
    });
    $(document).on('click', '[data-sort]', function () {
      var s = $(this).data('sort');
      if (S.sort === s) S.dir = S.dir === 'desc' ? 'asc' : 'desc'; else { S.sort = s; S.dir = s === 'date' ? 'desc' : 'asc'; }
      S.offset = 0; loadList();
    });
    $(document).on('click', '[data-page]', function () { S.offset = Math.max(0, S.offset + (+$(this).data('page')) * (S.prefs.page_size || 50)); loadList(); $scroll.scrollTop(0); });
    $(document).on('click', '[data-action=refresh]', function () { var $i = $(this).find('.bi').addClass('spin'); loadList(true).always(function () { $i.removeClass('spin'); }); poll(false); });
    $(document).on('click', '[data-action=mark-all-read]', function () {
      if (!S.folder) return;
      M4W.post('api/folders/' + S.folder + '/read-all').done(function (r) { setFolders(r.folders); loadList(true); });
    });
    $(document).on('click', '[data-action=empty-folder]', function () {
      var f = S.byId[S.folder];
      M4W.confirm(t('confirm_empty', { name: f.name }), t('confirm_empty_text'), t('empty_folder')).done(function () {
        M4W.post('api/folders/' + f.id + '/empty').done(function (r) { setFolders(r.folders); closeReader(); loadList(); M4W.toast(t('folder_emptied', { n: r.count }), 'success'); });
      });
    });
    $(document).on('click', '[data-action=compose]', function () { M4W.Compose.open({ mode: 'new' }); });
    $(document).on('click', '[data-action=shortcuts]', function () { bootstrap.Modal.getOrCreateInstance($('#m4w-shortcuts')[0]).show(); });

    // drag & drop to folders
    var dragIds = [];
    $scroll.on('dragstart', '.m4w-row', function (e) {
      var id = +$(this).data('id');
      dragIds = S.selected[id] ? selectedIds() : [id];
      var $g = $('<div class="m4w-drag-ghost"></div>').text(t('move_n', { n: dragIds.length })).appendTo('body');
      var dt = e.originalEvent.dataTransfer;
      dt.effectAllowed = 'move';
      dt.setData('text/plain', dragIds.join(','));
      try { dt.setDragImage($g[0], 10, 10); } catch (err) {}
      setTimeout(function () { $g.remove(); }, 0);
      $(this).addClass('dragging');
    }).on('dragend', '.m4w-row', function () { $(this).removeClass('dragging'); $('.drop-hover').removeClass('drop-hover'); });
    $(document).on('dragover', '.m4w-nav-item[data-folder]', function (e) { if (!dragIds.length) return; e.preventDefault(); $(this).addClass('drop-hover'); })
      .on('dragleave', '.m4w-nav-item[data-folder]', function () { $(this).removeClass('drop-hover'); })
      .on('drop', '.m4w-nav-item[data-folder]', function (e) {
        e.preventDefault(); $(this).removeClass('drop-hover');
        var fid = +$(this).data('folder');
        if (dragIds.length && fid !== S.folder) bulk('move', dragIds.slice(), { folder: fid });
        dragIds = [];
      });

    // reader interactions
    $reader.on('click', '[data-reader]', function () {
      var a = $(this).data('reader'), id = S.activeId;
      if (a === 'back') { closeReader(); return; }
      if (a === 'prev') { moveCursor(-1); return; }
      if (a === 'next') { moveCursor(1); return; }
      if (a === 'star') { toggleStar(id); return; }
      if (a === 'unread') { action('unread', [id], { thread: S.prefs.conversations && !isSentLike() }); closeReader(); return; }
      bulk(a, [id]);
    });
    $reader.on('click', '[data-toggle-msg]', function (e) {
      if ($(e.target).closest('a, button, .dropdown').length) return;
      var $m = $(this).closest('.m4w-msg');
      var expand = $m.hasClass('collapsed');
      $m.toggleClass('collapsed', !expand);
      if (expand && !$m.find('iframe').length) { $m.find('.m4w-msg-body').html(iframeHtml($m.data('msg'))); bindIframes($m); }
    });
    $reader.on('click', '[data-expand-thread]', function () { $(this).next('[data-hidden-thread]').removeClass('d-none'); $(this).remove(); bindIframes($reader); });
    $reader.on('click', '[data-show-images]', function (e) {
      e.preventDefault();
      var id = $(this).data('show-images'), $m = $(this).closest('.m4w-msg');
      $m.find('.m4w-msg-body').html(iframeHtml(id, true)); bindIframes($m);
      $(this).closest('.m4w-msg-banner').remove();
    });
    $reader.on('click', '[data-always-images]', function (e) {
      e.preventDefault();
      S.prefs.show_images = 1; M4W.savePrefs({ show_images: 1 });
      $reader.find('[data-show-images]').trigger('click');
      M4W.toast(t('images_always'), 'success');
    });
    $reader.on('click', '[data-unsubscribe]', function (e) {
      e.preventDefault();
      var id = $(this).data('unsubscribe');
      M4W.confirm(t('unsubscribe'), t('unsubscribe_confirm'), t('unsubscribe')).done(function () {
        M4W.post('api/messages/' + id + '/unsubscribe').done(function (r) {
          if (r.done) M4W.toast(t('unsubscribed'), 'success');
          else if (r.url) window.open(r.url, '_blank', 'noopener,noreferrer');
          else if (r.mailto) M4W.Compose.open({ mode: 'new', to: r.mailto, subject: r.subject || 'unsubscribe' });
        });
      });
    });
    $reader.on('click', '[data-msg-act]', function (e) {
      e.preventDefault();
      var act = $(this).data('msg-act');
      var id = +($(this).data('msg-id') || $(this).closest('.m4w-msg').data('msg') || S.activeId);
      if (act === 'reply' || act === 'reply_all' || act === 'forward') M4W.Compose.open({ mode: act, id: id });
      if (act === 'forward_att') M4W.Compose.open({ mode: 'forward', id: id, attachOriginal: true });
      if (act === 'edit') M4W.Compose.open({ mode: 'edit', id: id });
      if (act === 'star') toggleStar(id);
      if (act === 'unread') { action('unread', [id], { noReload: false }); closeReader(); }
      if (act === 'delete') { bulk('delete', [id]); }
      if (act === 'rule') {
        var msg = (S.current.thread || []).filter(function (m) { return m.id === id; })[0] || S.current.message;
        window.location.href = M4W.url('settings/rules', { from: msg.from.email, subject: msg.subject });
      }
    });

    // search
    $('#m4w-search-form').on('submit', function (e) {
      e.preventDefault();
      var q = String($('#m4w-search').val() || '').trim();
      if (q) location.hash = 'q/' + encodeURIComponent(q); else if (S.byRole.inbox) go(S.byRole.inbox.id);
      $('#m4w-search').trigger('blur');
    });
    $(document).on('click', '[data-action=clear-search]', function () { $('#m4w-search').val('').trigger('input'); if (S.q && S.byRole.inbox) go(S.byRole.inbox.id); });
    $(document).on('click', '[data-action=adv-search]', function () {
      var $a = $('#m4w-adv-search'), parts = [];
      var q = function (n) { return String($a.find('[name=' + n + ']').val() || '').trim(); };
      var quote = function (v) { return /\s/.test(v) ? '"' + v + '"' : v; };
      if (q('adv_from')) parts.push('from:' + quote(q('adv_from')));
      if (q('adv_to')) parts.push('to:' + quote(q('adv_to')));
      if (q('adv_subject')) parts.push('subject:' + quote(q('adv_subject')));
      if (q('adv_after')) parts.push('after:' + q('adv_after'));
      if (q('adv_before')) parts.push('before:' + q('adv_before'));
      if ($a.find('[name=adv_att]').is(':checked')) parts.push('has:attachment');
      if ($a.find('[name=adv_unread]').is(':checked')) parts.push('is:unread');
      if ($a.find('[name=adv_flag]').is(':checked')) parts.push('is:starred');
      if (q('adv_words')) parts.push(q('adv_words'));
      $('#m4w-search').val(parts.join(' ')).trigger('input');
      bootstrap.Dropdown.getOrCreateInstance($a.prev('[data-bs-toggle]')[0]).hide();
      $('#m4w-search-form').trigger('submit');
    });

    // quick settings
    $(document).on('change', 'input[name=qs_density]', function () { $('body').attr('data-density', this.value); S.prefs.density = this.value; M4W.savePrefs({ density: this.value }); });
    $(document).on('change', 'input[name=qs_pane]', function () {
      $main.removeClass('pane-right pane-bottom pane-off').addClass('pane-' + this.value); S.prefs.reading_pane = this.value; M4W.savePrefs({ reading_pane: this.value });
    });
    $(document).on('change', '#qs_threads', function () { S.prefs.conversations = this.checked ? 1 : 0; M4W.savePrefs({ conversations: S.prefs.conversations }); S.offset = 0; loadList(); });
  }

  function openSearchResult(id) {
    var it = itemById(id);
    if (!it) return;
    if (it.draft) { M4W.Compose.open({ mode: 'draft', id: id }); return; }
    openMessage(id);
  }

  function contextMenu(x, y) {
    $('.m4w-ctx').remove();
    var ids = selectedIds();
    var anyUnread = ids.some(function (id) { var it = itemById(id); return it && it.unread; });
    var one = ids.length === 1 ? ids[0] : null;
    var html = '<div class="dropdown-menu show m4w-ctx" style="left:' + x + 'px;top:' + y + 'px">'
      + (one ? '<button class="dropdown-item" data-ctx="reply"><i class="bi bi-reply"></i>' + esc(t('reply')) + '</button><button class="dropdown-item" data-ctx="reply_all"><i class="bi bi-reply-all"></i>' + esc(t('reply_all')) + '</button><button class="dropdown-item" data-ctx="forward"><i class="bi bi-forward"></i>' + esc(t('forward')) + '</button><div class="dropdown-divider"></div>' : '')
      + '<button class="dropdown-item" data-ctx="archive"><i class="bi bi-archive"></i>' + esc(t('archive')) + '</button>'
      + '<button class="dropdown-item" data-ctx="delete"><i class="bi bi-trash3"></i>' + esc(t('delete')) + '</button>'
      + '<button class="dropdown-item" data-ctx="' + (anyUnread ? 'read' : 'unread') + '"><i class="bi bi-envelope' + (anyUnread ? '-open' : '') + '"></i>' + esc(anyUnread ? t('mark_read') : t('mark_unread')) + '</button>'
      + '<button class="dropdown-item" data-ctx="flag"><i class="bi bi-star"></i>' + esc(t('star')) + '</button>'
      + '<button class="dropdown-item" data-ctx="spam"><i class="bi bi-exclamation-octagon"></i>' + esc(t('spam')) + '</button>'
      + '<div class="dropdown-divider"></div><h6 class="dropdown-header">' + esc(t('move_to')) + '</h6>'
      + S.folders.filter(function (f) { return f.role !== 'drafts' && f.role !== 'sent' && f.id !== S.folder; }).map(function (f) { return '<button class="dropdown-item" data-move-to="' + f.id + '"><i class="bi bi-' + esc(f.icon) + '"></i>' + esc(f.name) + '</button>'; }).join('')
      + '</div>';
    var $menu = $(html).appendTo('body');
    var w = $menu.outerWidth(), h = $menu.outerHeight();
    if (x + w > window.innerWidth) $menu.css('left', Math.max(4, x - w));
    if (y + h > window.innerHeight) $menu.css('top', Math.max(4, window.innerHeight - h - 8));
    $menu.on('click', '[data-ctx]', function () {
      var a = $(this).data('ctx');
      if (a === 'reply' || a === 'reply_all' || a === 'forward') M4W.Compose.open({ mode: a, id: one });
      else bulk(a);
      $menu.remove();
    });
    setTimeout(function () { $(document).one('click contextmenu', function () { $menu.remove(); }); }, 0);
  }

  // ------------------------------------------------------------ keyboard
  function bindKeyboard() {
    var gPrefix = false, gTimer;
    $(document).on('keydown', function (e) {
      if (S.prefs.shortcuts === 0) return;
      var $t = $(e.target);
      if ($t.is('input, textarea, select, [contenteditable=true]') || $t.closest('[contenteditable=true], .modal.show, .m4w-compose').length) {
        if (e.key === 'Escape' && $t.is('#m4w-search')) $t.trigger('blur');
        return;
      }
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      var k = e.key;
      if (gPrefix) {
        gPrefix = false; clearTimeout(gTimer);
        var role = { i: 'inbox', s: 'sent', d: 'drafts', a: 'archive', t: 'trash' }[k.toLowerCase()];
        if (role && S.byRole[role]) { e.preventDefault(); go(S.byRole[role].id); }
        return;
      }
      var ids = currentIds();
      switch (k) {
        case 'g': gPrefix = true; gTimer = setTimeout(function () { gPrefix = false; }, 1200); break;
        case 'c': e.preventDefault(); M4W.Compose.open({ mode: 'new' }); break;
        case '/': e.preventDefault(); $('#m4w-search').trigger('focus').trigger('select'); break;
        case '?': bootstrap.Modal.getOrCreateInstance($('#m4w-shortcuts')[0]).show(); break;
        case 'j': case 'ArrowDown': if (k === 'ArrowDown' && !S.activeId) { e.preventDefault(); } moveCursor(1); break;
        case 'k': case 'ArrowUp': if (k === 'ArrowUp' && !S.activeId) { e.preventDefault(); } moveCursor(-1); break;
        case 'o': case 'Enter': if (S.items[S.cursor]) go(S.folder, S.items[S.cursor].id); break;
        case 'u': case 'Escape': if (S.activeId) closeReader(); else clearSelection(); break;
        case 'x': if (S.items[S.cursor]) toggleSelect(S.items[S.cursor].id); break;
        case 's': if (ids[0]) toggleStar(ids[0]); break;
        case 'e': bulk('archive', ids); break;
        case '#': case 'Delete': bulk('delete', ids); break;
        case '!': bulk('spam', ids); break;
        case 'r': if (S.activeId) M4W.Compose.open({ mode: 'reply', id: lastThreadId() }); break;
        case 'a': if (S.activeId) M4W.Compose.open({ mode: 'reply_all', id: lastThreadId() }); break;
        case 'f': if (S.activeId) M4W.Compose.open({ mode: 'forward', id: lastThreadId() }); break;
        case 'v': { var $mv = $reader.find('[data-move-current]').prev('[data-bs-toggle]'); if (!$mv.length) $mv = $list.find('[data-move-menu]').prev('[data-bs-toggle]'); if ($mv.length && ids.length) bootstrap.Dropdown.getOrCreateInstance($mv[0]).show(); break; }
        case 'I': action('read', ids); break;
        case 'U': action('unread', ids); break;
        default: return;
      }
    });
  }
  function lastThreadId() {
    var th = S.current && S.current.thread;
    return th && th.length ? th[th.length - 1].id : S.activeId;
  }
})(jQuery, window, document);
