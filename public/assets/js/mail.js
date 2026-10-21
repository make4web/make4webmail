/*! Make4WebMail — webmail single page application */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;
  M4W.mailPage = true; // its own checks (poll) keep the bubbles up to date, not notify.js
  M4W.mailRefresh = function () { poll(false, true); }; // a push arrived: check at once

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
      // In the background too, once a minute: new mail is announced by the app (its sound), and the
      // server knows the app is open here (no system notification on top, see WebPush::send).
      setInterval(function () { if (!document.hidden || Date.now() - (S.lastPoll || 0) > 55000) poll(false); }, 30000);
      // Quick check of the open folder every 10 s (a few bytes): changed elsewhere, everything is
      // refreshed at once instead of at the next full check.
      setInterval(function () {
        var f = S.folder && !S.q ? S.folder : 0;
        if (document.hidden || !f || S.checking || !S.stamp || S.stamp.folder !== f) return;
        S.checking = true;
        M4W.get('api/stamp', { folder: f, device: M4W.Notify ? M4W.Notify.device() : '' }, { silent: true, quiet: true }).done(function (r) {
          if (r.stamp && S.stamp && S.stamp.folder === f && r.stamp !== S.stamp.value) poll(false);
        }).always(function () { S.checking = false; });
      }, 10000);
      if (boot.compose && M4W.Compose) M4W.Compose.open({ mode: 'new', to: boot.compose.to, subject: boot.compose.subject, files: boot.compose.files });
    });
    bindUi();
    bindKeyboard();
    // Connection lost or back: the list is drawn again (messages not kept greyed, or not).
    var wasOffline = false;
    if (M4W.Offline && M4W.Offline.onChange) M4W.Offline.onChange(function (o) {
      if (o.offline === wasOffline) return;
      wasOffline = o.offline;
      if (S.items) renderList();
      if (!o.offline && S.folder) { loadList(true); poll(false); }
    });
    // Folder menus: the open one goes above the following rows of the list.
    $(document).on('show.bs.dropdown', '.m4w-folder-dd', function () { $(this).addClass('open'); })
      .on('hidden.bs.dropdown', '.m4w-folder-dd', function () { $(this).removeClass('open'); });
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
  /** @param {boolean} [arrived] figures from the server's check: an increase is mail that arrived. */
  function setFolders(list, arrived) {
    var before = {};
    (S.folders || []).forEach(function (f) { before[f.id] = f.unread; });
    S.folders = list; S.byId = {}; S.byRole = {};
    list.forEach(function (f) { S.byId[f.id] = f; if (f.role) S.byRole[f.role] = f; });
    renderFolders();
    updateTitle();
    // A count that went up catches the eye for a moment (not on the first display).
    if (S.foldersShown) {
      list.forEach(function (f) { if (f.unread > (before[f.id] || 0)) bump($('.m4w-nav-item[data-folder="' + f.id + '"] .m4w-count')); });
    }
    S.foldersShown = true;
    // The mailbox on screen counts with its live figures (read here a second ago, not 30 s ago).
    var cur = String($('.m4w-topbar').data('current-box'));
    if (S.boxes && cur !== '-1') { S.boxes[cur] = liveUnread(); refreshTotal(!!arrived); }
  }
  /** Unread mail waiting in the mailbox on screen: inbox and own folders. */
  function liveUnread() {
    return (S.folders || []).reduce(function (s, f) { return s + (f.role === 'inbox' || !f.role ? (+f.unread || 0) : 0); }, 0);
  }
  function bump($el) { $el.removeClass('m4w-bump'); void ($el[0] && $el[0].offsetWidth); $el.addClass('m4w-bump'); }

  // Unread mail of all the mailboxes (account menu: each one), kept up to date by the checks.
  function setBoxes(counts, arrived) {
    S.boxes = {};
    Object.keys(counts).forEach(function (id) {
      var n = +counts[id] || 0;
      S.boxes[id] = n;
      $('[data-box-unread="' + id + '"]').each(function () {
        var was = +$(this).text() || 0;
        $(this).text(n).toggleClass('d-none', !n);
        if (n > was) bump($(this));
      });
    });
    var cur = String($('.m4w-topbar').data('current-box'));
    if (cur !== '-1' && S.folders) S.boxes[cur] = liveUnread();
    refreshTotal(!!arrived);
  }

  // The total of all the mailboxes, everywhere it can be seen without opening anything: the
  // avatar, the tab (title and a bubble on its icon), the icon of the installed app.
  /** @param {boolean} [arrived] an increase comes from mail that arrived (not from "mark unread" here). */
  function refreshTotal(arrived) {
    var total = 0;
    Object.keys(S.boxes || {}).forEach(function (id) { total += S.boxes[id]; });
    var $t = $('[data-boxes-elsewhere]'), was = +$t.text() || 0;
    $t.text(total).toggleClass('d-none', !total);
    // New mail since the last look: the bubble pulses (a halo, not a blink) until the person
    // does something on the page. Not on the first display.
    if (total > was && S.totalShown && arrived) { bump($t); $t.addClass('m4w-pulse'); S.pulseSince = Date.now(); }
    if (!total) $t.removeClass('m4w-pulse');
    S.totalShown = true;
    S.total = total;
    if (M4W.Notify) M4W.Notify.setUnread(total);
    updateTitle();
    tabBadge(total);
    try {
      if (navigator.setAppBadge) { total ? navigator.setAppBadge(total) : navigator.clearAppBadge(); }
    } catch (e) { /* not supported here */ }
  }

  $(document).on('pointerdown keydown', function () {
    // Seen: a second after it started at the earliest (not the click that was already under way).
    if (S.pulseSince && Date.now() - S.pulseSince > 1000) { $('[data-boxes-elsewhere]').removeClass('m4w-pulse'); S.pulseSince = 0; }
  });

  // A bubble on the tab's icon (notify.js: the same on every page).
  function tabBadge(n) { if (M4W.Notify) M4W.Notify.tabBadge(n); }
  M4W.setFolders = setFolders;

  function folderItem(f, depth) {
    var count = f.role === 'drafts' ? f.total : f.unread;
    var showCount = count > 0 && f.role !== 'sent' && f.role !== 'trash' && (f.role !== 'archive');
    // New mail (inbox, own folders) as a coloured pill; drafts and spam stay discreet.
    var isNew = showCount && (f.role === 'inbox' || !f.role);
    var icon = f.color ? '<span class="m4w-dot" style="background:' + esc(f.color) + '"></span>' : '<i class="bi bi-' + esc(f.icon) + (f.role === 'inbox' && f.unread ? '-fill' : '') + '"></i>';
    var html = '<li class="m4w-nav-li"><a class="m4w-nav-item' + (f.unread && f.role !== 'drafts' ? ' has-unread' : '') + '" href="#f/' + f.id + '" data-folder="' + f.id + '" style="' + (depth ? 'padding-left:' + (1.1 + depth * 1.1) + 'rem' : '') + '">'
      + icon + '<span class="m4w-nav-label">' + esc(f.name) + '</span>'
      + (showCount ? '<span class="m4w-count' + (isNew ? ' m4w-new-badge' : '') + '">' + count + '</span>' : '') + '</a>'
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
    $('#m4w-user-folders').html(out || '<li class="px-3 small m4w-nav-empty" style="padding-left:1.1rem!important">' + esc(t('no_folders')) + '</li>');
    highlightFolder();
    renderMoveMenus();
  }

  function highlightFolder() {
    $('.m4w-sidebar .m4w-nav-item').removeClass('active');
    if (S.q === 'is:starred') $('[data-search="is:starred"]').addClass('active');
    else if (S.folder && !S.q) $('.m4w-nav-item[data-folder="' + S.folder + '"]').addClass('active');
    var f = S.byId[S.folder];
    $('#m4w-folder-title').text(S.q ? (S.q === 'is:starred' ? t('starred') : t('search_results', { q: S.q })) : (f ? f.name : ''));
    var ficon = S.q ? (S.q === 'is:starred' ? 'star' : 'search') : (!f || f.role === 'inbox' ? 'envelope' : (f.icon || 'folder2-open'));
    $('#m4w-folder-icon').attr('title', $('#m4w-folder-title').text()).html('<i class="bi bi-' + esc(ficon) + '"></i>');
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
    // Unread mail waiting in all the mailboxes (this one live), as on the avatar.
    var n = S.total !== undefined ? S.total : liveUnread();
    if (S.brand === undefined) S.brand = document.title.split(' · ').pop().replace(/^\(\d+\)\s*/, '');
    var f = S.byId[S.folder];
    document.title = (n ? '(' + n + ') ' : '') + (f ? f.name + ' · ' : '') + S.brand;
  }

  // ---------------------------------------------------------------- list
  function renderSkeleton() {
    var h = '';
    for (var i = 0; i < 9; i++) h += '<div class="m4w-skeleton"><i style="width:36px;height:36px;border-radius:var(--m4w-radius-round)"></i><div class="flex-grow-1"><i style="width:' + (30 + (i * 17) % 40) + '%;margin-bottom:8px"></i><i style="width:' + (55 + (i * 13) % 35) + '%"></i></div></div>';
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
      if (r.stamp && params.folder && !params.q) S.stamp = { folder: params.folder, value: r.stamp };
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
    if (m.attachments) {
      // Red mark: a file is blocked (program, virus); yellow: one needs care before opening.
      var attLabel = (m.att_count > 1 ? t('n_files_attached', { n: m.att_count }) : t('has_attachments')) + (m.att_risk ? ' — ' + t('att_risk_' + m.att_risk) : '');
      icons += '<span class="m4w-row-att' + (m.att_risk ? ' risk-' + m.att_risk : '') + '" title="' + esc(attLabel) + '"><i class="bi bi-paperclip" aria-hidden="true"></i>'
        + (m.att_risk ? '<i class="bi bi-exclamation-circle-fill m4w-row-att-risk" aria-hidden="true"></i>' : '')
        + (m.att_count > 1 ? m.att_count : '') + '<span class="visually-hidden">' + esc(attLabel) + '</span></span>';
    }
    if (m.answered) icons += '<i class="bi bi-reply" title="' + esc(t('answered')) + '"></i>';
    if (m.forwarded) icons += '<i class="bi bi-forward" title="' + esc(t('forwarded')) + '"></i>';
    var folderChip = (S.q && S.byId[m.folder_id] && S.byId[m.folder_id].role !== 'inbox') ? '<span class="m4w-chip-folder">' + esc(S.byId[m.folder_id].name) + '</span>' : '';
    // Offline: a message not kept on the device is there, but greyed (readable when back online).
    var off = M4W.Offline && M4W.Offline.offline && !M4W.Offline.hasMsg(m.id);
    // Arrived while the app is open: flashed once when it comes in, "New" until read.
    var fresh = m.unread && S.fresh && S.fresh[m.id];
    if (fresh === 1) S.fresh[m.id] = 2;
    return '<div class="m4w-row' + (m.unread ? ' unread' : '') + (fresh ? ' fresh' : '') + (fresh === 1 ? ' fresh-in' : '') + (m.pinned ? ' pinned' : '') + (off ? ' m4w-unavailable' : '') + (S.selected[m.id] ? ' checked' : '') + (S.activeId === m.id ? ' active' : '') + '" data-id="' + m.id + '" draggable="true"' + (S.activeId === m.id ? ' aria-current="true"' : '') + '>'
      + '<input type="checkbox" class="form-check-input m4w-check" ' + (S.selected[m.id] ? 'checked' : '') + ' aria-label="' + esc(t('select')) + '">'
      + '<button type="button" class="m4w-star' + (m.flagged ? ' on' : '') + '" aria-label="' + esc(t('star')) + '"><i class="bi bi-star' + (m.flagged ? '-fill' : '') + '"></i></button>'
      + M4W.avatar(who, whoEmail, '', sent || m.draft ? '' : m.bimi)
      + '<div class="m4w-row-main"><div class="m4w-row-line1">'
      + (m.scheduled ? '<span class="badge badge-soft me-1" title="' + esc(M4W.date(m.scheduled, true)) + '"><i class="bi bi-clock"></i> ' + esc(new Date(m.scheduled * 1000).toLocaleString(document.documentElement.lang, { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })) + '</span>' : (m.draft ? '<span class="text-danger fw-semibold small me-1">' + esc(t('draft')) + '</span>' : ''))
      + (sent && !m.draft ? '<span class="text-muted small">' + esc(t('to_prefix')) + '</span>' : '')
      + (m.pinned ? '<i class="bi bi-pin-angle-fill m4w-row-pin" role="img" title="' + esc(t('pinned')) + '" aria-label="' + esc(t('pinned')) + '"></i>' : '')
      + '<span class="m4w-row-from">' + esc(who) + '</span>'
      + (fresh ? '<span class="m4w-new-pill">' + esc(t('new_badge')) + '</span>' : '')
      + (m.thread_count > 1 ? '<span class="m4w-row-count">' + m.thread_count + '</span>' : '')
      + '</div><div class="m4w-row-subject">' + folderChip + ' ' + esc(m.subject || t('no_subject')) + '</div>'
      + '<div class="m4w-row-snippet">' + esc(m.snippet || '') + '</div></div>'
      + '<div class="m4w-row-meta"><span class="m4w-row-date" title="' + esc(M4W.date(m.date, true)) + '">' + esc(M4W.date(m.date)) + '</span><span class="m4w-row-icons">' + icons + '</span></div>'
      + '<div class="m4w-row-hover">'
      + '<button class="btn btn-ghost btn-icon" data-row-act="' + (m.pinned ? 'unpin' : 'pin') + '" title="' + esc(m.pinned ? t('unpin') : t('pin')) + '" aria-label="' + esc(m.pinned ? t('unpin') : t('pin')) + '"><i class="bi bi-pin-angle' + (m.pinned ? '-fill' : '') + '"></i></button>'
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
      if (S.q === 'is:starred') { icon = 'star'; title = t('empty_starred'); hint = t('empty_starred_hint'); }
      $scroll.html('<div class="m4w-empty" data-aos="fade-up"><div class="m4w-empty-icon"><i class="bi bi-' + icon + '"></i></div><h3>' + esc(title) + '</h3><p class="small">' + esc(hint) + '</p></div>');
      $('#m4w-pager').html('');
      syncSelectionUi();
      return;
    }
    var html = '', lastGroup = null;
    var pinnedSep = false;
    S.items.forEach(function (m) {
      if (m.pinned && !S.q) {
        if (!pinnedSep) { html += '<div class="m4w-day-sep"><i class="bi bi-pin-angle-fill me-1"></i>' + esc(t('pinned_group')) + '</div>'; pinnedSep = true; }
        html += rowHtml(m);
        return;
      }
      if (pinnedSep && lastGroup === null && S.sort !== 'date') { html += '<div class="m4w-day-sep">' + esc(t('others_group')) + '</div>'; lastGroup = ''; }
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
  // Phones: a long press selects, then a tap adds or removes a message (no Ctrl / Shift there).
  var phoneMq = window.matchMedia ? window.matchMedia('(max-width: 767.98px)') : null;
  function isPhone() { return !!(phoneMq && phoneMq.matches); }
  function selectedIds() { return Object.keys(S.selected).map(Number); }
  function clearSelection() { S.selected = {}; $scroll.find('.m4w-row.checked').removeClass('checked').find('.m4w-check').prop('checked', false); syncSelectionUi(); }
  function toggleSelect(id, on) {
    if (on === undefined) on = !S.selected[id];
    if (on) S.selected[id] = true; else delete S.selected[id];
    rowById(id).toggleClass('checked', on).find('.m4w-check').prop('checked', on);
    syncSelectionUi();
  }
  function syncSelectionUi() {
    var n = selectedIds().length;
    $list.toggleClass('has-selection', n > 0);
    $('#m4w-sel-count, [data-sel-count]').text(n ? t('selected_count', { n: n }) : '');
    var all = S.items.length && n === S.items.length;
    $('#m4w-select-all').prop('checked', !!all).prop('indeterminate', n > 0 && !all);
  }

  // --------------------------------------------------------------- reader
  function openMessage(id) {
    var item = itemById(id);
    if (M4W.Offline && M4W.Offline.offline && !M4W.Offline.hasMsg(id)) {
      M4W.toast(M4W.t('off_msg_unavailable'), 'warning');
      return;
    }
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
    var $c = $('#m4w-reader-content').removeClass('d-none').addClass('d-flex').html('<div class="m4w-reader-toolbar"></div><div class="m4w-reader-scroll"><div class="m4w-skeleton border-0"><i style="width:60%;height:18px"></i></div><div class="m4w-skeleton border-0"><i style="width:36px;height:36px;border-radius:var(--m4w-radius-round)"></i><div class="flex-grow-1"><i style="width:40%;margin-bottom:8px"></i><i style="width:25%"></i></div></div></div>');
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
          var it = itemById(id); if (it) { it.unread = false; rowById(id).removeClass('unread fresh fresh-in').find('.m4w-new-pill').remove(); }
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

  // Risky attachment: explain before opening.
  $(document).on('click', '[data-att-warn]', function (e) {
    if (this.dataset.confirmed) { delete this.dataset.confirmed; return; }
    e.preventDefault();
    var link = this, why = $(this).attr('data-att-warn');
    $('#m4w-att-confirm').remove();
    var $m = $('<div class="modal fade" id="m4w-att-confirm" tabindex="-1" aria-labelledby="m4w-att-confirm-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title fs-5" id="m4w-att-confirm-title"><i class="bi bi-exclamation-triangle text-warning me-2"></i>' + esc(t('att_confirm_title')) + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + esc(t('close')) + '"></button></div>'
      + '<div class="modal-body"><p>' + esc(why) + '</p><p class="small text-muted mb-0">' + esc(t('att_confirm_hint')) + '</p></div>'
      + '<div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">' + esc(t('cancel')) + '</button><button type="button" class="btn btn-light text-danger" data-att-go>' + esc(t('att_confirm_open')) + '</button></div>'
      + '</div></div></div>').appendTo(document.body);
    $m.on('click', '[data-att-go]', function () { bootstrap.Modal.getInstance($m[0]).hide(); link.dataset.confirmed = '1'; link.click(); })
      .on('hidden.bs.modal', function () { $m.remove(); });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  });

  // ------------------------------------------------------------ attachment viewer
  var ATT_DOC = /\.(docx|xlsx|pptx|odt|csv|tsv|txt|log|md)$/i;
  function attViewKind(a) {
    if (a.risk || a.removed) return '';
    if (/^image\/(png|jpe?g|gif|webp)$/.test(a.mime)) return 'img';
    if (a.mime === 'application/pdf') return 'pdf';
    if (ATT_DOC.test(a.name || '')) return 'doc';
    return '';
  }
  // "Download to my computer / tablet / phone", whichever this is.
  function deviceKind() {
    var ua = navigator.userAgent || '';
    if (/iPad|Tablet/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'tablet';
    if (/Mobi|iPhone|iPod|Android/i.test(ua)) return 'phone';
    return 'computer';
  }
  M4W.deviceKind = deviceKind;
  var attViews = {};
  function openAttachment(msgId, idx) {
    var list = attViews[msgId] || [], a = list[idx];
    if (!a) return;
    var base = 'api/messages/' + msgId + '/part/' + encodeURIComponent(a.part);
    var kind = attViewKind(a), inlineUrl = M4W.url(base, { inline: 1 }), dlUrl = M4W.url(base);
    var $m = $('#m4w-att-view');
    if (!$m.length) {
      $m = $('<div class="modal fade" id="m4w-att-view" tabindex="-1" aria-labelledby="m4w-att-view-title" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down"><div class="modal-content">'
        + '<div class="modal-header gap-2"><span class="m4w-att-view-icon" data-icon></span><div class="min-w-0 flex-grow-1"><h2 class="modal-title fs-6 text-truncate" id="m4w-att-view-title"></h2><div class="small text-muted" data-size></div></div>'
        + '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-att-nav="-1" aria-label="' + esc(t('prev')) + '"><i class="bi bi-chevron-left"></i></button><span class="small text-muted" data-pos></span><button type="button" class="btn btn-ghost btn-icon btn-sm" data-att-nav="1" aria-label="' + esc(t('next')) + '"><i class="bi bi-chevron-right"></i></button>'
        + '<button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="' + esc(t('close')) + '"></button></div>'
        + '<div class="modal-body m4w-att-view-body" data-body></div>'
        + '<div class="modal-footer flex-wrap gap-2">'
        + '<button type="button" class="btn btn-light" data-att-files><i class="bi bi-folder-plus me-1"></i>' + esc(t('att_save_files')) + '</button>'
        + '<a class="btn btn-light" data-att-dl download><i class="bi bi-download me-1"></i>' + esc(t('download_to_' + deviceKind())) + '</a>'
        + '<button type="button" class="btn btn-primary" data-att-fwd><i class="bi bi-send me-1"></i>' + esc(t('att_send')) + '</button>'
        + '</div></div></div></div>').appendTo(document.body);
      $m.on('hidden.bs.modal', function () { $m.find('[data-body]').empty(); });
      $m.on('keydown', function (e) { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { var $b = $m.find('[data-att-nav="' + (e.key === 'ArrowLeft' ? -1 : 1) + '"]'); if (!$b.prop('disabled')) $b.trigger('click'); } });
    }
    var ic = M4W.fileIcon(a.name, a.mime);
    $m.find('[data-icon]').attr('class', 'm4w-att-view-icon m4w-att-icon ' + ic[0]).html('<i class="bi bi-' + ic[1] + '"></i>');
    $m.find('#m4w-att-view-title').text(a.name);
    $m.find('[data-size]').text(M4W.bytes(a.size));
    $m.find('[data-pos]').text(list.length > 1 ? (idx + 1) + ' / ' + list.length : '');
    $m.find('[data-att-nav]').toggleClass('d-none', list.length < 2).each(function () { var d = +$(this).attr('data-att-nav'); $(this).prop('disabled', !list[idx + d]).off('click').on('click', function () { openAttachment(msgId, idx + d); }); });
    var $b = $m.find('[data-body]').empty();
    if (kind === 'img') M4W.zoomable($('<img alt="">').attr({ src: inlineUrl, alt: a.name, title: t('zoom_hint') }).appendTo($b)[0]);
    else if (kind === 'pdf') $b.append($('<iframe referrerpolicy="no-referrer"></iframe>').attr({ title: a.name, src: inlineUrl }));
    else if (kind === 'doc') $b.append($('<iframe sandbox="" referrerpolicy="no-referrer"></iframe>').attr({ title: a.name, src: M4W.url(base + '/preview') }));
    else $b.append('<div class="m4w-att-view-none"><i class="bi bi-' + ic[1] + '"></i><p>' + esc(t('att_no_preview')) + '</p></div>');
    $m.find('[data-att-dl]').attr('href', dlUrl);
    $m.find('[data-att-files]').toggleClass('d-none', !M4W.FilesPicker).attr({ 'data-save-files': msgId, 'data-parts': a.part });
    $m.find('[data-att-fwd]').off('click').on('click', function () { bootstrap.Modal.getInstance($m[0]).hide(); M4W.Compose.open({ mode: 'attach', id: msgId, part: a.part }); });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  }
  $(document).on('click', '[data-att-view]', function (e) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return; // new tab still possible
    e.preventDefault();
    var v = String($(this).attr('data-att-view')).split(':');
    openAttachment(+v[0], +v[1]);
  });

  function attachmentsHtml(msg) {
    var atts = (msg.attachments || []).filter(function (a) { return !a.inline; });
    if (!atts.length) return '';
    attViews[msg.id] = atts;
    var total = 0;
    var html = atts.map(function (a, idx) {
      total += a.size;
      if (a.removed) {
        // Removed by the antivirus: still listed, so nobody wonders where it went.
        return '<div class="m4w-att is-removed" title="' + esc(t('att_removed_title', { signature: a.removed.signature || '?' })) + '"><span class="m4w-att-icon"><i class="bi bi-shield-x"></i></span>'
          + '<div class="min-w-0 flex-grow-1"><div class="m4w-att-name">' + esc(a.name) + '</div><div class="m4w-att-size text-danger">' + esc(t('att_removed')) + (a.removed.signature ? ' · ' + esc(a.removed.signature) : '') + '</div></div></div>';
      }
      var why = a.risk ? t('att_why_' + a.why, { kind: a.kind || '' }) : '';
      if (a.risk === 'block') {
        return '<div class="m4w-att is-blocked" title="' + esc(why) + '"><span class="m4w-att-icon"><i class="bi bi-shield-exclamation"></i></span>'
          + '<div class="min-w-0 flex-grow-1"><div class="m4w-att-name">' + esc(a.name) + '</div><div class="m4w-att-size text-danger">' + esc(t('att_blocked')) + ' · ' + esc(a.kind) + '</div></div></div>';
      }
      var warnAttrs = a.risk === 'warn' ? ' data-att-warn="' + esc(why) + '"' : '';
      var ic = M4W.fileIcon(a.name, a.mime);
      var inlineUrl = M4W.url('api/messages/' + msg.id + '/part/' + encodeURIComponent(a.part), { inline: 1 });
      var dlUrl = M4W.url('api/messages/' + msg.id + '/part/' + encodeURIComponent(a.part));
      var thumb = ic[0] === 'img' ? '<img src="' + esc(inlineUrl) + '" alt="" loading="lazy">' : '<i class="bi bi-' + ic[1] + '"></i>';
      var previewable = /^image\/(png|jpe?g|gif|webp)$/.test(a.mime) || a.mime === 'application/pdf' || a.mime === 'text/plain';
      // Pictures, PDF and office documents open in a window over the message.
      var view = attViewKind(a) ? ' data-att-view="' + msg.id + ':' + idx + '"' : '';
      return '<div class="m4w-att' + (a.risk === 'warn' ? ' is-warn' : '') + '" title="' + esc(a.name) + '"><a class="m4w-att-icon ' + ic[0] + '" href="' + esc(previewable ? inlineUrl : dlUrl) + '" target="_blank" rel="noopener"' + warnAttrs + view + ' tabindex="-1" aria-hidden="true">' + thumb + '</a>'
        + '<a class="min-w-0 flex-grow-1 text-reset" href="' + esc(previewable ? inlineUrl : dlUrl) + '" target="_blank" rel="noopener"' + warnAttrs + view + '><div class="m4w-att-name">' + esc(a.name) + '</div><div class="m4w-att-size">' + (a.risk === 'warn' ? '<span class="text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>' + esc(t('att_warn')) + '</span> · ' : '') + esc(M4W.bytes(a.size)) + '</div></a>'
        + (M4W.FilesPicker ? '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-save-files="' + msg.id + '" data-parts="' + esc(a.part) + '" title="' + esc(t('fs_save_to')) + '" aria-label="' + esc(t('fs_save_to')) + '"><i class="bi bi-folder-plus"></i></button>' : '')
        + '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-att-send="' + msg.id + '" data-part="' + esc(a.part) + '" title="' + esc(t('att_send')) + '" aria-label="' + esc(t('att_send')) + '"><i class="bi bi-send"></i></button>'
        + '<a class="btn btn-ghost btn-icon btn-sm" href="' + esc(dlUrl) + '" title="' + esc(t('download_to_' + deviceKind())) + '" aria-label="' + esc(t('download_to_' + deviceKind())) + '" download' + warnAttrs + '><i class="bi bi-download"></i></a></div>';
    }).join('');
    var zip = atts.length > 1 ? ' · <a href="' + esc(M4W.url('api/messages/' + msg.id + '/zip')) + '"><i class="bi bi-file-zip"></i> ' + esc(t('download_all')) + '</a>' : '';
    if (atts.length > 1 && M4W.FilesPicker) zip += ' · <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-save-files="' + msg.id + '" data-parts="' + esc(atts.map(function (a) { return a.part; }).join(',')) + '"><i class="bi bi-folder-plus"></i> ' + esc(t('fs_save_all_to')) + '</button>';
    return '<div class="m4w-msg-atts"><div class="w-100 small text-muted mb-1"><i class="bi bi-paperclip"></i> ' + esc(t('n_attachments', { n: atts.length, size: M4W.bytes(total) })) + zip + '</div>' + html + '</div>';
  }

  // Padlock after the sender's address: is the sender really who the From says (SenderCheck.php)?
  function senderLock(c) {
    if (!c || !c.state || c.state === 'none') return '';
    var look = { internal: ['building-lock', 'text-primary'], verified: ['lock-fill', 'text-success'], partial: ['lock', 'text-muted'], failed: ['unlock', 'text-warning'] }[c.state];
    if (!look) return '';
    var rows = c.state === 'internal' ? '' : [['SPF', c.spf], ['DKIM', (c.dkim || '').replace(/@/g, ' · ').replace(/,/g, ', ')], ['DMARC', c.dmarc]].filter(function (r) { return r[1] && r[1] !== 'none'; })
      .map(function (r) { return '<tr><td class="text-muted pe-2">' + r[0] + '</td><td class="text-break">' + esc(r[1]) + '</td></tr>'; }).join('');
    return ' <span class="dropdown m4w-sender-lock"><button type="button" class="btn btn-link p-0 align-baseline ' + look[1] + '" data-bs-toggle="dropdown" title="' + esc(t('lock_' + c.state)) + '" aria-label="' + esc(t('lock_' + c.state)) + '"><i class="bi bi-' + look[0] + '"></i></button>'
      + '<div class="dropdown-menu p-3 small" style="min-width:260px;max-width:380px"><div class="fw-semibold mb-1"><i class="bi bi-' + look[0] + ' ' + look[1] + ' me-1"></i>' + esc(t('lock_' + c.state)) + '</div>'
      + '<div class="text-muted mb-2">' + esc(t('lock_' + c.state + '_text')) + '</div>'
      + (rows ? '<table class="w-100">' + rows + '</table>' : '') + (c.by ? '<div class="text-muted mt-1">' + esc(t('lock_by', { by: c.by })) + '</div>' : '') + '</div></span>';
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
      + (msg.fetched ? '<tr><td class="text-muted pe-2 align-top">' + esc(t('fetched_from')) + '</td><td>' + esc(msg.fetched.box) + ' <span class="text-muted">(' + esc(t('fetched_' + msg.fetched.kind)) + (msg.fetched.folder && msg.fetched.folder !== 'INBOX' ? ', ' + esc(msg.fetched.folder) : '') + ')</span></td></tr>' : '')
      + '</table></div></span></div>';
    var banner = '';
    var verdict = msg.auth && msg.auth.verdict;
    if (verdict && verdict !== 'ok' && verdict !== 'internal') {
      banner += '<div class="m4w-msg-banner danger" role="alert"><i class="bi bi-shield-exclamation"></i><span><strong>' + esc(t('auth_' + verdict + '_title')) + '</strong> ' + esc(t('auth_' + verdict + '_text', { domain: (from.email || '').split('@').pop() })) + '</span></div>';
    }
    if (msg.impersonation) {
      var im = msg.impersonation;
      banner += '<div class="m4w-msg-banner danger" role="alert"><i class="bi bi-person-exclamation"></i><span><strong>' + esc(t('imp_' + im.kind + '_title')) + '</strong> '
        + esc(t('imp_' + im.kind + '_text', { address: im.address, colleague: im.colleague || '', domain: im.domain || '', real: im.real || '' })) + '</span></div>';
    }
    if (msg.spam) {
      // In Junk: say why, and let the person correct the filter in one click.
      banner += '<div class="m4w-msg-banner warn"><i class="bi bi-funnel-fill text-warning"></i><span>'
        + (msg.spam.reasons.length ? '<strong>' + esc(t('spam_why', { score: msg.spam.score })) + '</strong> ' + esc(msg.spam.reasons.join(' · ')) : '<strong>' + esc(t('spam_in_junk')) + '</strong>')
        + '</span><button type="button" class="btn btn-sm btn-success ms-auto text-nowrap" data-not-spam="' + msg.id + '"><i class="bi bi-hand-thumbs-up me-1"></i>' + esc(t('not_spam_btn')) + '</button></div>';
    }
    if (msg.delivery && msg.delivery.length) {
      // What became of it, recipient by recipient (delivery reports, see DeliveryReports.php).
      var cls = { queued: 'warning', handed: 'primary', delayed: 'warning', relayed: 'success', delivered: 'success', failed: 'danger' };
      var ico = { queued: 'hourglass-split', handed: 'send', delayed: 'hourglass-split', relayed: 'send-check', delivered: 'check2-all', failed: 'x-octagon' };
      banner += '<div class="m4w-msg-banner m4w-delivery"><i class="bi bi-truck text-muted"></i><span class="d-flex flex-column gap-1 min-w-0"><strong>' + esc(t('dlv_title')) + '</strong>'
        + msg.delivery.map(function (d) {
          return '<span class="small"><span class="badge badge-soft-' + cls[d.state] + ' me-1"><i class="bi bi-' + ico[d.state] + ' me-1"></i>' + esc(t('dlv_' + d.state)) + '</span>'
            + esc(d.recipient) + ' <span class="text-muted">· ' + esc(M4W.date(d.at, true)) + '</span>'
            + (d.state === 'handed' && !d.dsn ? ' <span class="text-muted">· ' + esc(t('dlv_no_reports')) + '</span>' : '')
            + (d.detail && d.detail !== 'local' ? '<span class="d-block text-muted text-break">' + esc(d.detail) + '</span>' : '') + '</span>';
        }).join('') + '</span></div>';
    }
    if (msg.reply_target) {
      banner += '<div class="m4w-msg-banner warn"><i class="bi bi-reply-fill text-warning"></i><span>' + esc(t('reply_target_' + msg.reply_target)) + '</span></div>';
    }
    if (msg.trackers > 0) {
      banner += '<div class="m4w-msg-banner"><i class="bi bi-eye-slash text-success"></i><span>' + esc(t('trackers_removed', { n: msg.trackers })) + '</span></div>';
    }
    if (msg.blocked_images > 0) {
      banner += '<div class="m4w-msg-banner"><i class="bi bi-shield-lock text-primary"></i><span>' + esc(t('images_blocked', { n: msg.blocked_images })) + '</span>'
        + '<a href="#" data-show-images="' + msg.id + '" class="fw-semibold">' + esc(t('show_images')) + '</a> · <a href="#" data-always-images class="text-muted">' + esc(t('always_show_images')) + '</a></div>';
    }
    if (msg.unsubscribe) {
      banner += '<div class="m4w-msg-banner"><i class="bi bi-envelope-slash text-muted"></i><span>' + esc(t('newsletter_hint')) + '</span><a href="#" data-unsubscribe="' + msg.id + '" class="fw-semibold">' + esc(t('unsubscribe')) + '</a></div>';
    }
    return '<article class="m4w-msg' + (expanded ? '' : ' collapsed') + '" data-msg="' + msg.id + '">'
      + '<div class="m4w-msg-head" data-toggle-msg>' + M4W.avatar(from.name || from.email, from.email, 'lg', msg.bimi, msg.bimi_vmc)
      + '<div class="m4w-msg-who"><div><span class="m4w-msg-name">' + esc(from.name || from.email) + '</span> <span class="m4w-msg-email">&lt;' + esc(from.email) + '&gt;</span>' + senderLock(msg.sender_check) + '</div>'
      + rcpt + '<div class="m4w-msg-snippet">' + esc(msg.snippet) + '</div></div>'
      + '<div class="d-flex align-items-start gap-1"><div class="m4w-msg-date pt-1">' + (msg.attachments.some(function (a) { return !a.inline; }) ? '<i class="bi bi-paperclip me-1"></i>' : '') + esc(M4W.date(msg.date)) + '<div class="small-2 d-none d-md-block">' + esc(M4W.relative(msg.date)) + '</div></div>'
      + '<span class="m4w-msg-actions-inline d-flex"><button class="btn btn-ghost btn-icon btn-sm" data-msg-act="star" title="' + esc(t('star')) + '"><i class="bi bi-star' + (msg.flagged ? '-fill text-warning' : '') + '"></i></button>'
      + '<button class="btn btn-ghost btn-icon btn-sm" data-msg-act="reply_all" title="' + esc(t('reply_all')) + '"><i class="bi bi-reply-all"></i></button>'
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
      // Attachments at the top, under the sender (after the warnings): no scrolling down a long message.
      + attachmentsHtml(msg)
      + invitationHtml(msg)
      + '<div class="m4w-msg-body">' + (expanded ? iframeHtml(msg.id) : '') + '</div>'
      + '</article>';
  }

  /** Meeting invitation, update, cancellation or reply carried by the message. */
  function invitationHtml(msg) {
    var v = msg.invitation;
    if (!v) return '';
    var head = { REQUEST: v.in_calendar && v.outdated ? 'inv_update' : 'inv_title', CANCEL: 'inv_cancel', REPLY: 'inv_reply', PUBLISH: 'inv_publish' }[v.method] || 'inv_title';
    var rows = '<dt>' + esc(t('inv_when')) + '</dt><dd>' + esc(v.when) + (v.recurring ? ' <span class="badge badge-soft-primary">' + esc(t('inv_recurring')) + '</span>' : '') + '</dd>'
      + (v.location ? '<dt>' + esc(t('inv_where')) + '</dt><dd>' + esc(v.location) + '</dd>' : '')
      + (v.organizer ? '<dt>' + esc(t('inv_organizer')) + '</dt><dd>' + esc(v.organizer) + '</dd>' : '');
    var notes = '';
    if (v.cancelled) notes += '<p class="text-danger mb-2"><i class="bi bi-x-octagon me-1"></i>' + esc(t('inv_cancelled')) + '</p>';
    if (v.outdated) notes += '<p class="text-muted mb-2"><i class="bi bi-clock-history me-1"></i>' + esc(t('inv_outdated')) + '</p>';
    if (v.replier) { var r = v.replier.split('|'); notes += '<p class="mb-2">' + esc(t('inv_replied', { name: r[0], answer: t('cal_ps_' + (r[1] || 'needs_action').toLowerCase().replace('-', '_')) })) + '</p>'; }
    if (v.conflicts && v.conflicts.length) notes += '<p class="text-warning-emphasis mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + esc(t('inv_conflict', { list: v.conflicts.join(', ') })) + '</p>';
    var actions = '';
    if (v.can_answer && !v.cancelled && !v.outdated) {
      ['ACCEPTED', 'TENTATIVE', 'DECLINED'].forEach(function (ps) {
        var icon = { ACCEPTED: 'check-lg text-success', TENTATIVE: 'question-lg text-warning', DECLINED: 'x-lg text-danger' }[ps];
        var on = v.partstat === ps;
        actions += '<button type="button" class="btn btn-light btn-sm' + (on ? ' active' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '" data-inv-answer="' + ps + '" data-inv-msg="' + msg.id + '"><i class="bi bi-' + icon + ' me-1"></i>' + esc(t({ ACCEPTED: 'cal_accept', TENTATIVE: 'cal_maybe', DECLINED: 'cal_decline' }[ps])) + '</button>';
      });
      actions = '<div class="btn-group flex-wrap" role="group" aria-label="' + esc(t('inv_title')) + '">' + actions + '</div>';
    } else if (v.method === 'PUBLISH' && !v.in_calendar) {
      actions = '<button type="button" class="btn btn-light btn-sm" data-inv-import="' + msg.id + '"><i class="bi bi-calendar-plus me-1"></i>' + esc(t('inv_add')) + '</button>';
    }
    if (v.join && !v.cancelled) actions += ' <a class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer" href="' + esc(v.join) + '"><i class="bi bi-camera-video me-1"></i>' + esc(v.join_label ? t('inv_join_with', { provider: v.join_label }) : t('inv_join')) + '</a>';
    // Connection details of the online meeting (ID, code, phone), as the organizer's application wrote them.
    if (v.join && !v.cancelled && v.join_details && v.join_details.length) {
      notes += '<div class="m4w-meet-box mb-2"><dl class="m4w-invite-dl mb-1">' + v.join_details.map(function (d) { return '<dt>' + esc(d[0]) + '</dt><dd class="m4w-code">' + esc(d[1]) + '</dd>'; }).join('') + '</dl>'
        + (v.join_howto ? '<div class="small text-muted">' + esc(v.join_howto) + '</div>' : '') + '</div>';
    }
    var status = v.in_calendar ? '<span class="small text-muted"><i class="bi bi-calendar-check me-1"></i>' + esc(t('inv_in_calendar'))
      + (v.partstat && v.partstat !== 'NEEDS-ACTION' && v.method === 'REQUEST' ? ' · ' + esc(t('inv_answered', { answer: t('cal_ps_' + v.partstat.toLowerCase().replace('-', '_')) })) : '') + '</span>' : '';
    return '<section class="m4w-invite" aria-label="' + esc(t(head)) + '"><div class="m4w-invite-icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></div><div class="min-w-0 flex-grow-1">'
      + '<div class="small text-muted text-uppercase fw-semibold">' + esc(t(head)) + '</div><h3 class="m4w-invite-title">' + esc(v.title) + '</h3><dl class="m4w-invite-dl">' + rows + '</dl>' + notes
      + '<div class="d-flex flex-wrap align-items-center gap-2">' + actions + status + ' <a class="small" href="' + esc(M4W.url('calendar')) + '">' + esc(t('inv_open')) + '</a></div></div></section>';
  }
  $(document).on('click', '[data-inv-answer], [data-inv-import]', function () {
    var $b = $(this), id = +($b.data('inv-msg') || $b.data('inv-import')), $card = $b.closest('.m4w-invite');
    var body = $b.data('inv-import') ? { message_id: id, action: 'import' } : { message_id: id, partstat: $b.data('inv-answer') };
    $card.find('button').prop('disabled', true);
    M4W.post('api/calendar/respond', body).done(function (r) {
      $card.replaceWith(invitationHtml({ id: id, invitation: r.invitation }));
      M4W.toast(body.action ? t('inv_added') : t('inv_sent'), 'success');
    }).fail(function () { $card.find('button').prop('disabled', false); });
  });

  function iframeHtml(id, images) {
    // Offline: the document kept on the device (pictures inside), shown in the same sandbox.
    if (M4W.Offline && M4W.Offline.offline) {
      return '<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="' + esc(t('message_content')) + '" data-offline-body="' + (+id) + '"></iframe>';
    }
    var src = M4W.url('api/messages/' + id + '/body', images ? { images: 1 } : null);
    return '<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="lazy" title="' + esc(t('message_content')) + '" src="' + esc(src) + '"></iframe>';
  }

  // Space the reading pane leaves to a message shown alone (desktop): the content gets a fixed
  // area down to the bottom of the pane and scrolls inside it; 0 when the content decides
  // (several messages open, small screens).
  function paneSpace(frame) {
    if (window.innerWidth < 768) return 0;
    var $scroll = $(frame).closest('.m4w-reader-scroll');
    if (!$scroll.length || $scroll.find('.m4w-msg:not(.collapsed)').length !== 1) return 0;
    var sc = $scroll[0], scTop = sc.getBoundingClientRect().top, top = frame.getBoundingClientRect().top - scTop + sc.scrollTop;
    // What follows the content (attachments, reply buttons, the pane's bottom padding).
    var last = sc.lastElementChild, end = last ? last.getBoundingClientRect().bottom - scTop + sc.scrollTop : top + frame.offsetHeight;
    var after = end - (top + frame.offsetHeight) + (parseFloat(window.getComputedStyle(sc).paddingBottom) || 0);
    var space = sc.clientHeight - top - after;
    return space >= 240 ? Math.floor(space) : 0;
  }

  function fitIframe(frame) {
    try {
      var doc = frame.contentDocument;
      if (!doc) return;
      var h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
      // Padding and border are inside the height (border-box: the dark theme pads the frame).
      var cs = window.getComputedStyle(frame);
      var extra = cs.boxSizing === 'border-box' ? (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0) + (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0) : 0;
      var space = paneSpace(frame);
      frame.style.height = (space || (h + extra + 4)) + 'px';
      frame.classList.toggle('m4w-frame-fixed', !!space);
    } catch (e) { frame.style.height = '600px'; }
  }
  $(window).on('resize', M4W.debounce(function () { $('.m4w-reader-scroll .m4w-msg-body iframe').each(function () { fitIframe(this); }); }, 150));

  function bindIframes($ctx) {
    if (M4W.Offline && M4W.Offline.fillBodies) M4W.Offline.fillBodies($ctx);
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
      + (inSpam ? '<button class="btn btn-sm btn-success mx-1 text-nowrap" data-reader="not_spam" title="' + esc(t('not_spam_hint')) + '"><i class="bi bi-hand-thumbs-up me-1"></i>' + esc(t('not_spam_btn')) + '</button>' : '<button class="btn btn-ghost btn-icon" data-reader="spam" title="' + esc(t('spam')) + ' (!)"><i class="bi bi-exclamation-octagon"></i></button>')
      + (folder.role !== 'sent' && folder.role !== 'drafts' ? '<button class="btn btn-ghost btn-icon" data-reader="phish" title="' + esc(t('phish_report')) + '" aria-label="' + esc(t('phish_report')) + '"><i class="bi bi-shield-exclamation"></i></button>' : '')
      + '<button class="btn btn-ghost btn-icon" data-reader="delete" title="' + esc(inTrash ? t('delete_forever') : t('delete')) + ' (#)"><i class="bi bi-trash3"></i></button>'
      + '<span class="vr"></span>'
      + '<button class="btn btn-ghost btn-icon" data-reader="unread" title="' + esc(t('mark_unread')) + ' (Shift+U)"><i class="bi bi-envelope"></i></button>'
      + '<span class="dropdown"><button class="btn btn-ghost btn-icon" data-bs-toggle="dropdown" title="' + esc(t('move')) + ' (V)"><i class="bi bi-folder-symlink"></i></button><div class="dropdown-menu" data-move-menu data-move-current></div></span>'
      + '<button class="btn btn-ghost btn-icon" data-reader="star" title="' + esc(t('star')) + ' (S)"><i class="bi bi-star' + (msg.flagged ? '-fill text-warning' : '') + '"></i></button>'
      + (folder.role !== 'trash' && folder.role !== 'spam' ? '<button class="btn btn-ghost btn-icon" data-reader="' + (msg.pinned ? 'unpin' : 'pin') + '" title="' + esc(msg.pinned ? t('unpin') : t('pin')) + '" aria-label="' + esc(msg.pinned ? t('unpin') : t('pin')) + '" aria-pressed="' + (msg.pinned ? 'true' : 'false') + '"><i class="bi bi-pin-angle' + (msg.pinned ? '-fill text-primary' : '') + '"></i></button>' : '')
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
      body += '<div class="m4w-quick-reply"><button class="btn btn-light" data-msg-act="reply_all" data-msg-id="' + last.id + '"><i class="bi bi-reply-all me-1"></i>' + esc(t('reply_all')) + '</button>'
        + '<button class="btn btn-light" data-msg-act="reply" data-msg-id="' + last.id + '"><i class="bi bi-reply me-1"></i>' + esc(t('reply')) + '</button>'
        + '<button class="btn btn-light" data-msg-act="forward" data-msg-id="' + last.id + '"><i class="bi bi-forward me-1"></i>' + esc(t('forward')) + '</button></div>';
    }
    var $c = $('#m4w-reader-content');
    $c.html('<div class="m4w-reader-toolbar">' + tb + '</div><div class="m4w-reader-scroll" tabindex="-1">' + body + '</div>');
    renderMoveMenus();
    bindIframes($c);
    $c.find('[data-bs-toggle=dropdown]').each(function () { bootstrap.Dropdown.getOrCreateInstance(this, { popperConfig: { strategy: 'fixed' } }); });
  }

  /** One person reports, the whole organisation is protected. */
  function reportPhishing(id) {
    M4W.confirm(t('phish_confirm_title'), t('phish_confirm_text'), t('phish_report')).done(function () {
      M4W.post('api/messages/' + id + '/phishing', {}).done(function (r) {
        rowById(id).slideUp(120);
        closeReader();
        setFolders(r.folders);
        loadList(true);
        M4W.toast(r.internal ? t('phish_done_internal') : (r.removed ? t('phish_done_n', { n: r.removed }) : t('phish_done')), 'success');
      });
    });
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

  var LABELS = { archive: 'archived', delete: 'deleted', spam: 'marked_spam', move: 'moved', inbox: 'moved_inbox', purge: 'deleted_forever', not_spam: 'not_spam_done' };

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
      var removing = ['archive', 'delete', 'spam', 'move', 'inbox', 'purge', 'not_spam'].indexOf(n) !== -1;
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
          var msgTxt = n === 'not_spam' && r.trusted && r.trusted.length ? t('not_spam_trusted', { n: ids.length, sender: r.trusted.join(', ') }) : t(LABELS[n], { n: ids.length });
          M4W.toast(msgTxt, 'success', n === 'purge' ? {} : {
            action: { label: t('undo'), fn: function () { (n === 'not_spam' ? action('spam', moved) : action('move', moved, { folder: origin })).done(function () { M4W.toast(t('undone'), 'info'); }); } }
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

  // Webmail mode: tell the user when their provider mailbox is not reachable.
  function renderRemote(rm) {
    var $f = $('#m4w-remote-flag');
    if (!$f.length) $f = $('<div id="m4w-remote-flag"></div>').insertAfter('#m4w-vacation-flag');
    if (!rm || rm.status === 'ok' || rm.status === 'pending') { $f.empty(); return; }
    $f.html('<div class="m4w-remote-flag" role="status"><i class="bi bi-plug"></i><span>' + esc(t('wm_flag_' + rm.status)) + '</span></div>');
  }

  // ------------------------------------------------------------- polling
  // One check at a time: asked again while one runs (back in front, the timer and a push at
  // once), it is made once more right after; each new message is told once.
  var polling = null, pollAgain = false, pollForce = false, toasted = {};
  function poll(first, force) {
    if (polling && !first) { pollAgain = true; pollForce = pollForce || !!force; return polling; }
    var q = { since: S.maxId }, onFolder = S.folder && !S.q ? S.folder : 0;
    if (M4W.Notify && M4W.Notify.device()) { q.device = M4W.Notify.device(); q.away = M4W.Notify.away(); } // heartbeat (WebPush::send)
    S.lastPoll = Date.now();
    if (force) q.force = 1;
    if (onFolder) q.folder = onFolder;
    polling = M4W.get('api/poll', q, { silent: true, quiet: true });
    polling.always(function () {
      polling = null;
      if (pollAgain) { var f = pollForce; pollAgain = pollForce = false; setTimeout(function () { poll(false, f); }, 0); }
    });
    return polling.done(function (r) {
      if (r.remote) renderRemote(r.remote);
      setFolders(r.folders, !first);
      if (r.boxes) setBoxes(r.boxes, !first);
      if (r.csrf) M4W.csrf(r.csrf);
      var fresh = (r.new || []).filter(function (x) { return !toasted[x.id]; });
      fresh.forEach(function (x) { toasted[x.id] = 1; });
      if (!first && fresh.length) {
        S.fresh = S.fresh || {};
        fresh.forEach(function (x) { if (!S.fresh[x.id]) S.fresh[x.id] = 1; });
        var n = fresh[0];
        M4W.toast(t('new_mail_from', { name: n.from_name || n.from_email }) + ' — ' + (n.subject || t('no_subject')), 'info', {
          action: { label: t('open'), fn: function () { go(+n.folder_id || S.byRole.inbox.id, +n.id); } }
        });
        // Its sound (and vibration), and the app's notification when looking elsewhere (notify.js).
        // Only what the server held for this page: the rest was already announced by the system
        // notification (the app was asleep, taken for closed, see WebPush::send).
        if (M4W.Notify && M4W.Notify.mine(r, n.id)) M4W.Notify.announce(n, function () { go(+n.folder_id || S.byRole.inbox.id, +n.id); });
      }
      S.maxId = r.max_id;
      // Changed elsewhere (another device, the provider's mailbox, a rule): quietly refreshed,
      // the selection kept. Not while dragging or with a menu open.
      if (onFolder && r.stamp && onFolder === S.folder && !S.q) {
        var was = S.stamp && S.stamp.folder === onFolder ? S.stamp.value : null;
        S.stamp = { folder: onFolder, value: r.stamp };
        if (was !== null && was !== r.stamp && !$('.dropdown-menu.show, .m4w-ctx').length && !$('.m4w-row.m4w-swiping, .m4w-row.dragging').length) {
          loadList(true).done(function () {
            Object.keys(S.selected).forEach(function (id) { if (!itemById(id)) delete S.selected[id]; });
            syncSelectionUi();
          });
        }
      }
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
      if (isPhone() && selectedIds().length) { toggleSelect(id); S.lastClicked = id; return; }
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
    var press = null, pressedAt = 0;
    $scroll.on('pointerdown', '.m4w-row', function (e) {
      if (!isPhone() || e.originalEvent.pointerType === 'mouse') return;
      if ($(e.target).closest('.m4w-check, .m4w-star, .m4w-row-hover').length) return;
      var row = this, oe = e.originalEvent;
      clearTimeout(press && press.timer);
      press = { x: oe.clientX, y: oe.clientY, timer: setTimeout(function () {
        press = null; pressedAt = Date.now();
        toggleSelect(+$(row).data('id'), true);
        if (navigator.vibrate) { try { navigator.vibrate(20); } catch (err) { /* not allowed */ } }
      }, 500) };
    });
    $scroll.on('pointermove', '.m4w-row', function (e) {
      if (!press) return;
      var oe = e.originalEvent;
      if (Math.abs(oe.clientX - press.x) > 10 || Math.abs(oe.clientY - press.y) > 10) { clearTimeout(press.timer); press = null; }
    });
    $scroll.on('pointerup pointercancel', '.m4w-row', function () { if (press) { clearTimeout(press.timer); press = null; } });
    // The click that ends a long press must not unselect or open the message.
    $scroll[0].addEventListener('click', function (e) {
      if (pressedAt && Date.now() - pressedAt < 800) { pressedAt = 0; e.stopPropagation(); e.preventDefault(); }
    }, true);
    $(document).on('click', '[data-sel-close]', function () { clearSelection(); });
    // Swipe a row on a touch screen (as in the phone apps): the row follows the finger, the action
    // shows underneath, and past the threshold it is done (Settings: swipe_left / swipe_right).
    (function () {
      var sw = null, swallowClick = false;
      var ACTS = {
        delete: { icon: 'trash3', cls: 'is-delete', label: function () { return t('delete'); } },
        archive: { icon: 'archive', cls: 'is-archive', label: function () { return t('archive'); } },
        read: { icon: 'envelope-open', cls: 'is-read', label: function (it) { return t(it && it.unread ? 'swipe_read' : 'swipe_unread'); } },
        flag: { icon: 'star-fill', cls: 'is-flag', label: function (it) { return t(it && it.flagged ? 'unstar' : 'star'); } }
      };
      function actFor(dx) { var a = S.prefs[dx < 0 ? 'swipe_left' : 'swipe_right'] || (dx < 0 ? 'delete' : 'read'); return ACTS[a] ? a : null; }
      function reset(animate) {
        if (!sw) return;
        var s0 = sw; sw = null;
        s0.$row.css({ transition: animate ? 'transform .2s ease' : '', transform: '' });
        setTimeout(function () { s0.$row.removeClass('m4w-swiping').css({ transition: '', background: '', touchAction: '' }); s0.$under.remove(); }, animate ? 210 : 0);
      }
      $scroll.on('pointerdown', '.m4w-row', function (e) {
        // Not in selection mode: there a tap adds or removes a message.
        if (e.originalEvent.pointerType !== 'touch' || selectedIds().length || $(e.target).closest('.m4w-check, .m4w-star, [data-row-act]').length) return;
        sw = { $row: $(this), id: +$(this).data('id'), x0: e.clientX, y0: e.clientY, t0: Date.now(), dx: 0, dir: 0, armed: false, $under: $() };
      });
      $scroll.on('pointermove', '.m4w-row', function (e) {
        if (!sw || e.originalEvent.pointerType !== 'touch') return;
        var dx = e.clientX - sw.x0, dy = e.clientY - sw.y0;
        if (!sw.dir) {
          if (Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) { sw = null; return; } // scrolling the list
          if (Math.abs(dx) < 12 || Math.abs(dx) < Math.abs(dy) * 1.3) return;
          sw.dir = 1;
          try { this.setPointerCapture(e.originalEvent.pointerId); } catch (x) {}
          var $r = sw.$row, bg = getComputedStyle($r[0]).backgroundColor;
          sw.$under = $('<div class="m4w-swipe-under" aria-hidden="true"><span class="l"></span><span class="r"></span></div>')
            .css({ top: $r[0].offsetTop, height: $r[0].offsetHeight }).insertBefore($r);
          $r.addClass('m4w-swiping').css({ background: /rgba\(0, 0, 0, 0\)|transparent/.test(bg) ? 'var(--m4w-surface)' : bg });
        }
        e.preventDefault();
        sw.dx = dx;
        var a = actFor(dx), it = itemById(sw.id), w = sw.$row.outerWidth();
        if (!a) { sw.$row.css('transform', 'translateX(' + (dx / 4) + 'px)'); return; } // nothing configured: resists
        var conf = ACTS[a], armed = Math.abs(dx) > Math.max(80, w * 0.3);
        sw.$under.attr('class', 'm4w-swipe-under ' + conf.cls + (dx < 0 ? ' to-left' : ' to-right') + (armed ? ' armed' : ''))
          .find(dx < 0 ? '.r' : '.l').html('<i class="bi bi-' + (a === 'read' && it && !it.unread ? 'envelope' : conf.icon) + '"></i>' + esc(conf.label(it))).end()
          .find(dx < 0 ? '.l' : '.r').empty();
        if (armed && !sw.armed && navigator.vibrate) { try { navigator.vibrate(10); } catch (x) {} }
        sw.armed = armed;
        sw.$row.css('transform', 'translateX(' + dx + 'px)');
      });
      $scroll.on('pointerup pointercancel', '.m4w-row', function (e) {
        if (!sw) return;
        if (!sw.dir || e.type === 'pointercancel') { reset(true); return; }
        swallowClick = true; setTimeout(function () { swallowClick = false; }, 350);
        var dx = sw.dx, a = actFor(dx), fast = Math.abs(dx) > 50 && Math.abs(dx) / Math.max(1, Date.now() - sw.t0) > 0.6;
        if (!a || !(sw.armed || fast)) { reset(true); return; }
        var id = sw.id, it = itemById(id), s0 = sw;
        if (a === 'delete' || a === 'archive') {
          // Slides out, then goes as with the buttons (optimistic, with "Undo").
          s0.$row.css({ transition: 'transform .18s ease-in', transform: 'translateX(' + (dx < 0 ? '-' : '') + '110%)' });
          setTimeout(function () { sw = s0; reset(false); bulk(a, [id]); }, 180);
          sw = null;
          return;
        }
        reset(true);
        if (a === 'read' && it) {
          var act = it.unread ? 'read' : 'unread';
          it.unread = act === 'unread'; rowById(id).replaceWith(rowHtml(it));
          action(act, [id], { thread: S.prefs.conversations && !S.q, noReload: true, silent: true });
        } else if (a === 'flag') {
          toggleStar(id);
        }
      });
      // The tap that ends a swipe does not open the message.
      $scroll[0].addEventListener('click', function (e) { if (swallowClick && $(e.target).closest('.m4w-row').length) { e.stopPropagation(); e.preventDefault(); swallowClick = false; } }, true);
    })();
    $scroll.on('contextmenu', '.m4w-row', function (e) {
      e.preventDefault();
      if (isPhone()) return; // long press: handled above, no menu
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
    // Refresh first pulls from the provider in webmail mode, then reloads the list.
    $(document).on('click', '[data-action=refresh]', function () { var $i = $(this).find('.bi').addClass('spin'); poll(false, true).always(function () { loadList(true).always(function () { $i.removeClass('spin'); }); }); });
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
    $(document).on('click', '[data-not-spam]', function () { bulk('not_spam', [+$(this).attr('data-not-spam')]); });
    $(document).on('click', '[data-att-send]', function () { M4W.Compose.open({ mode: 'attach', id: +$(this).attr('data-att-send'), part: String($(this).attr('data-part')) }); });
    $(document).on('click', '[data-save-files]', function () {
      var id = $(this).attr('data-save-files'), parts = String($(this).attr('data-parts')).split(',');
      M4W.FilesPicker.pickFolder(t('fs_save_to'), t('fs_save')).done(function (folder) {
        M4W.post('api/messages/' + id + '/save-to-files', { folder_id: folder, parts: parts }).done(function (r) {
          M4W.toast(t('fs_saved_to'), 'success', { action: { label: t('open'), fn: function () { window.location.href = M4W.url('files') + '#f/' + folder; } } });
        });
      });
    });
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
      var a = $(this).attr('data-reader'), id = S.activeId;
      if (a === 'back') { closeReader(); return; }
      if (a === 'prev') { moveCursor(-1); return; }
      if (a === 'next') { moveCursor(1); return; }
      if (a === 'star') { toggleStar(id); return; }
      if (a === 'unread') { action('unread', [id], { thread: S.prefs.conversations && !isSentLike() }); closeReader(); return; }
      if (a === 'phish') { reportPhishing(id); return; }
      if (a === 'pin' || a === 'unpin') {
        var on = a === 'pin', $b = $(this);
        if (S.current) S.current.message.pinned = on;
        $b.attr({ 'data-reader': on ? 'unpin' : 'pin', title: t(on ? 'unpin' : 'pin'), 'aria-label': t(on ? 'unpin' : 'pin'), 'aria-pressed': on ? 'true' : 'false' }).find('.bi').attr('class', 'bi bi-pin-angle' + (on ? '-fill text-primary' : ''));
        action(a, [id], { thread: S.prefs.conversations && !isSentLike() });
        M4W.toast(t(on ? 'pinned_done' : 'unpinned_done'), 'success');
        return;
      }
      bulk(a, [id]);
    });
    $reader.on('click', '[data-toggle-msg]', function (e) {
      if ($(e.target).closest('a, button, .dropdown').length) return;
      var $m = $(this).closest('.m4w-msg');
      var expand = $m.hasClass('collapsed');
      $m.toggleClass('collapsed', !expand);
      if (expand && !$m.find('iframe').length) { $m.find('.m4w-msg-body').html(iframeHtml($m.data('msg'))); bindIframes($m); }
      // One or several open: fixed area or the content's own height.
      $m.closest('.m4w-reader-scroll').find('.m4w-msg-body iframe').each(function () { fitIframe(this); });
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
    // On this page a search stays here (the common script calls this on Enter or "Search").
    M4W.searchHere = function (q) {
      if (q) location.hash = 'q/' + encodeURIComponent(q); else if (S.byRole.inbox) go(S.byRole.inbox.id);
      $('#m4w-search').trigger('blur');
    };
    $(document).on('click', '[data-action=clear-search]', function () { $('#m4w-search').val('').trigger('input'); if (S.q && S.byRole.inbox) go(S.byRole.inbox.id); });

    // quick settings
    $(document).on('change', 'input[name=qs_density]', function () { $('body').attr('data-density', this.value); S.prefs.density = this.value; M4W.savePrefs({ density: this.value }); });
    $(document).on('change', 'input[name=qs_pane]', function () {
      $main.removeClass('pane-right pane-bottom pane-off').addClass('pane-' + this.value); S.prefs.reading_pane = this.value; M4W.savePrefs({ reading_pane: this.value });
      applySplit();
    });

    // Draggable border between the list and the reading pane, remembered on this device.
    var $split = $('#m4w-splitter'), $mainEl = $('#m4w-main'), $listEl = $('#m4w-list');
    function splitBottom() { return $mainEl.hasClass('pane-bottom'); }
    function splitKey() { return 'm4w.split.' + (splitBottom() ? 'bottom' : 'right'); }
    function splitLimits() {
      var total = splitBottom() ? $mainEl.height() : $mainEl.width();
      return { total: total, min: splitBottom() ? 160 : 280, max: total - (splitBottom() ? 180 : 380) };
    }
    function applySplit(px) {
      if (px === undefined) { try { px = +localStorage.getItem(splitKey()) || 0; } catch (e) { px = 0; } }
      var L = splitLimits();
      if (px && !$mainEl.hasClass('pane-off') && L.max > L.min) {
        px = Math.round(Math.max(L.min, Math.min(L.max, px)));
        $mainEl.addClass('resized'); $listEl.css('flex', '0 0 ' + px + 'px');
      } else {
        $mainEl.removeClass('resized'); $listEl.css('flex', '');
      }
      var cur = splitBottom() ? $listEl.outerHeight() : $listEl.outerWidth();
      $split.attr({ 'aria-orientation': splitBottom() ? 'horizontal' : 'vertical', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': L.total ? Math.round(cur / L.total * 100) : 50 });
      return px;
    }
    function saveSplit(px) { try { if (px) localStorage.setItem(splitKey(), String(px)); else localStorage.removeItem(splitKey()); } catch (e) { /* storage unavailable */ } }
    $split.on('pointerdown', function (e) {
      if (e.button !== 0) return;
      e.preventDefault();
      var o = $listEl[0].getBoundingClientRect(), bottom = splitBottom(), px = 0;
      $split.addClass('dragging'); $('body').addClass('m4w-resizing').toggleClass('m4w-resizing-v', bottom);
      $(document).on('pointermove.split', function (m) { px = applySplit(bottom ? m.clientY - o.top : m.clientX - o.left); });
      $(document).one('pointerup pointercancel', function () {
        $(document).off('pointermove.split'); $split.removeClass('dragging'); $('body').removeClass('m4w-resizing m4w-resizing-v');
        if (px) saveSplit(px);
      });
    });
    $split.on('dblclick', function () { saveSplit(0); applySplit(0); });
    $split.on('keydown', function (e) {
      var step = e.shiftKey ? 80 : 24, cur = splitBottom() ? $listEl.outerHeight() : $listEl.outerWidth(), d = 0;
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') d = -step;
      else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') d = step;
      else if (e.key === 'Home') { saveSplit(0); applySplit(0); e.preventDefault(); return; }
      else return;
      e.preventDefault();
      saveSplit(applySplit(cur + d));
    });
    $(window).on('resize', M4W.debounce(function () { applySplit(); }, 150));
    applySplit();
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
    var allPinned = ids.every(function (id) { var it = itemById(id); return it && it.pinned; });
    var html = '<div class="dropdown-menu show m4w-ctx" style="left:' + x + 'px;top:' + y + 'px">'
      + (one ? '<button class="dropdown-item" data-ctx="reply"><i class="bi bi-reply"></i>' + esc(t('reply')) + '</button><button class="dropdown-item" data-ctx="reply_all"><i class="bi bi-reply-all"></i>' + esc(t('reply_all')) + '</button><button class="dropdown-item" data-ctx="forward"><i class="bi bi-forward"></i>' + esc(t('forward')) + '</button><div class="dropdown-divider"></div>' : '')
      + '<button class="dropdown-item" data-ctx="archive"><i class="bi bi-archive"></i>' + esc(t('archive')) + '</button>'
      + '<button class="dropdown-item" data-ctx="delete"><i class="bi bi-trash3"></i>' + esc(t('delete')) + '</button>'
      + '<button class="dropdown-item" data-ctx="' + (anyUnread ? 'read' : 'unread') + '"><i class="bi bi-envelope' + (anyUnread ? '-open' : '') + '"></i>' + esc(anyUnread ? t('mark_read') : t('mark_unread')) + '</button>'
      + '<button class="dropdown-item" data-ctx="flag"><i class="bi bi-star"></i>' + esc(t('star')) + '</button>'
      + (allPinned ? '<button class="dropdown-item" data-ctx="unpin"><i class="bi bi-pin-angle"></i>' + esc(t('unpin')) + '</button>' : '<button class="dropdown-item" data-ctx="pin"><i class="bi bi-pin-angle-fill"></i>' + esc(t('pin')) + '</button>')
      + ((S.byId[S.folder] || {}).role === 'spam' ? '<button class="dropdown-item" data-ctx="not_spam"><i class="bi bi-hand-thumbs-up"></i>' + esc(t('not_spam_btn')) + '</button>' : '<button class="dropdown-item" data-ctx="spam"><i class="bi bi-exclamation-octagon"></i>' + esc(t('spam')) + '</button>')
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
      // (A ticked box or a pressed button keeps the shortcuts: only where one types.)
      if ($t.is('input:not([type=checkbox]):not([type=radio]):not([type=button]), textarea, select, [contenteditable=true]') || $t.closest('[contenteditable=true], .modal.show, .m4w-compose').length) {
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
        case '#': case 'Delete': case 'Backspace': e.preventDefault(); bulk('delete', ids); break; // Backspace: the "delete" key of a Mac
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
