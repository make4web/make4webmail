/*! Make4WebMail — calendar */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;
  var $root = $('[data-cal]');
  if (!$root.length) return;

  var BOOT = JSON.parse($('#cal-boot').text() || '{}');
  var LANG = BOOT.lang === 'en' ? 'en-GB' : 'fr-FR';
  var HOUR = 48; // px per hour in day / week views
  var S = { view: 'week', date: startOfDay(new Date()), calendars: BOOT.calendars || [], hidden: {}, events: [], req: null };
  try {
    S.view = localStorage.getItem('m4w.cal.view') || 'week';
    S.hidden = JSON.parse(localStorage.getItem('m4w.cal.hidden') || '{}') || {};
  } catch (e) { /* storage unavailable */ }
  if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches && S.view === 'week') S.view = 'day';

  // ---------------------------------------------------------------- dates
  function startOfDay(d) { var x = new Date(d); x.setHours(0, 0, 0, 0); return x; }
  function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function startOfWeek(d) { var x = startOfDay(d); var wd = (x.getDay() + 6) % 7; return addDays(x, -wd); }
  function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
  function ts(d) { return Math.floor(d.getTime() / 1000); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function fmt(d, o) { return d.toLocaleDateString(LANG, o); }
  function time(d) { return d.toLocaleTimeString(LANG, { hour: '2-digit', minute: '2-digit' }); }
  function parseLocal(date, tm) { var p = date.split('-'), q = (tm || '00:00').split(':'); return new Date(+p[0], +p[1] - 1, +p[2], +q[0], +q[1]); }

  function range() {
    var d = S.date;
    if (S.view === 'day') return [startOfDay(d), addDays(startOfDay(d), 1)];
    if (S.view === 'week') { var w = startOfWeek(d); return [w, addDays(w, 7)]; }
    if (S.view === 'list') return [startOfDay(d), addDays(startOfDay(d), 31)];
    var first = new Date(d.getFullYear(), d.getMonth(), 1), from = startOfWeek(first);
    return [from, addDays(from, 42)];
  }

  function calById(id) { for (var i = 0; i < S.calendars.length; i++) if (+S.calendars[i].id === +id) return S.calendars[i]; return null; }
  function visibleIds() { return S.calendars.filter(function (c) { return !S.hidden[c.id]; }).map(function (c) { return c.id; }); }
  function saveState() { try { localStorage.setItem('m4w.cal.view', S.view); localStorage.setItem('m4w.cal.hidden', JSON.stringify(S.hidden)); } catch (e) { /* ignore */ } }

  // ---------------------------------------------------------------- loading
  function load() {
    var r = range(), ids = visibleIds();
    renderTitle();
    if (!ids.length) { S.events = []; render(); return; }
    if (S.req && S.req.abort) S.req.abort();
    S.req = M4W.get('api/calendar/events', { from: ts(r[0]), to: ts(r[1]), cals: ids.join(',') }, { silent: true });
    S.req.done(function (res) { S.events = res.events || []; render(); });
  }

  function renderTitle() {
    var r = range(), title;
    if (S.view === 'day') title = fmt(S.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    else if (S.view === 'week') {
      var end = addDays(r[1], -1);
      title = r[0].getMonth() === end.getMonth() ? fmt(r[0], { day: 'numeric' }) + ' – ' + fmt(end, { day: 'numeric', month: 'long', year: 'numeric' })
        : fmt(r[0], { day: 'numeric', month: 'short' }) + ' – ' + fmt(end, { day: 'numeric', month: 'short', year: 'numeric' });
    } else if (S.view === 'list') title = t('cal_upcoming');
    else title = fmt(S.date, { month: 'long', year: 'numeric' });
    $('[data-cal-title]').text(title.charAt(0).toUpperCase() + title.slice(1));
    $('[data-cal-view]').each(function () { var on = $(this).data('cal-view') === S.view; $(this).toggleClass('active', on).attr('aria-pressed', on ? 'true' : 'false'); });
  }

  function render() {
    if (S.view === 'month' || S.view === 'list') S.scroll = null;
    var $b = $('[data-cal-body]').empty().attr('data-view', S.view);
    if (S.view === 'month') renderMonth($b);
    else if (S.view === 'list') renderList($b);
    else renderDays($b, S.view === 'day' ? 1 : 7);
    renderMini();
  }

  function evLabel(e) {
    var s = new Date(e.start * 1000), en = new Date(e.end * 1000);
    return (e.all_day ? t('cal_all_day') : time(s) + ' – ' + time(en)) + ', ' + (e.title || t('cal_untitled')) + (e.location ? ', ' + e.location : '');
  }
  function evClasses(e) {
    return 'm4w-cal-ev' + (e.status === 'CANCELLED' ? ' cancelled' : '') + (e.my_partstat === 'NEEDS-ACTION' ? ' pending' : '') + (e.my_partstat === 'TENTATIVE' || e.status === 'TENTATIVE' ? ' tentative' : '')
      + (e.my_partstat === 'DECLINED' ? ' declined' : '');
  }
  function evButton(e, extra) {
    return $('<button type="button"></button>').addClass(evClasses(e) + (extra ? ' ' + extra : '')).css('--ev', e.color)
      .attr('aria-label', evLabel(e) + (e.meeting ? ', ' + t('cal_has_meeting') : '') + (e.room_status === 'pending' ? ', ' + t('cal_room_pending') : ''))
      .data('ev', e).attr('draggable', e.writable ? 'true' : 'false');
  }
  /** Small marks after the title: online meeting, room waiting for approval. */
  function evMarks(e) {
    return (e.meeting ? ' <i class="bi bi-camera-video" aria-hidden="true"></i>' : '') + (e.room_status === 'pending' ? ' <i class="bi bi-hourglass-split" aria-hidden="true"></i>' : '');
  }

  // ---------------------------------------------------------------- month view
  function renderMonth($b) {
    var r = range(), today = new Date(), month = S.date.getMonth();
    var $grid = $('<div class="m4w-cal-month" role="grid"></div>').attr('aria-label', $('[data-cal-title]').text());
    var $head = $('<div class="m4w-cal-month-head" role="row"></div>');
    for (var i = 0; i < 7; i++) $head.append($('<div role="columnheader"></div>').text(fmt(addDays(r[0], i), { weekday: 'short' })));
    $grid.append($head);
    for (var w = 0; w < 6; w++) {
      var $row = $('<div class="m4w-cal-month-row" role="row"></div>');
      for (var d = 0; d < 7; d++) {
        var day = addDays(r[0], w * 7 + d), next = addDays(day, 1);
        var $cell = $('<div class="m4w-cal-day" role="gridcell"></div>').attr('data-date', ymd(day)).toggleClass('other', day.getMonth() !== month).toggleClass('today', sameDay(day, today));
        $cell.append($('<button type="button" class="m4w-cal-daynum"></button>').text(day.getDate()).attr('aria-label', fmt(day, { weekday: 'long', day: 'numeric', month: 'long' })).attr('data-goto', ymd(day)));
        var list = S.events.filter(function (e) { return e.start < ts(next) && (e.end > ts(day) || (e.end === e.start && e.start >= ts(day))); });
        list.sort(function (a, b) { return (b.all_day - a.all_day) || (a.start - b.start); });
        var max = 3;
        list.slice(0, list.length > max + 1 ? max : max + 1).forEach(function (e) {
          var $e = evButton(e, e.all_day || e.end - e.start >= 86400 ? 'bar' : 'dot');
          if (!e.all_day && e.end - e.start < 86400) $e.append($('<span class="tm"></span>').text(time(new Date(e.start * 1000))));
          $e.append($('<span class="ttl"></span>').text(e.title || t('cal_untitled')).append(evMarks(e)));
          $cell.append($e);
        });
        if (list.length > max + 1) $cell.append($('<button type="button" class="m4w-cal-more"></button>').text(t('cal_more', { n: list.length - max })).attr('data-goto', ymd(day)).attr('data-goto-view', 'day'));
        $row.append($cell);
      }
      $grid.append($row);
    }
    $b.append($grid);
  }

  // ---------------------------------------------------------------- day / week views
  function layoutDay(list) {
    // Overlapping events side by side: greedy columns per cluster.
    list.sort(function (a, b) { return (a.start - b.start) || (b.end - a.end); });
    var clusters = [], cur = null, curEnd = 0;
    list.forEach(function (e) {
      if (!cur || e.start >= curEnd) { cur = []; clusters.push(cur); curEnd = 0; }
      cur.push(e); curEnd = Math.max(curEnd, Math.max(e.end, e.start + 900));
    });
    clusters.forEach(function (c) {
      var cols = [];
      c.forEach(function (e) {
        for (var i = 0; i < cols.length; i++) if (cols[i] <= e.start) { e._col = i; cols[i] = Math.max(e.end, e.start + 900); return; }
        e._col = cols.length; cols.push(Math.max(e.end, e.start + 900));
      });
      c.forEach(function (e) { e._cols = cols.length; });
    });
    return list;
  }

  function renderDays($b, n) {
    var r = range(), today = new Date(), days = [];
    for (var i = 0; i < n; i++) days.push(addDays(r[0], i));
    var $wrap = $('<div class="m4w-cal-days"></div>').css('--days', n);
    var $head = $('<div class="m4w-cal-days-head"><div class="gutter"></div></div>');
    var $all = $('<div class="m4w-cal-allday"><div class="gutter small text-muted">' + esc(t('cal_all_day_short')) + '</div></div>');
    days.forEach(function (d) {
      $head.append($('<button type="button" class="m4w-cal-dayhead"></button>').toggleClass('today', sameDay(d, today)).attr('data-goto', ymd(d)).attr('data-goto-view', 'day')
        .append($('<span class="wd"></span>').text(fmt(d, { weekday: 'short' }))).append($('<span class="dn"></span>').text(d.getDate())));
      var $c = $('<div class="m4w-cal-allday-cell"></div>').attr('data-date', ymd(d));
      S.events.forEach(function (e) {
        if ((e.all_day || e.end - e.start >= 86400) && e.start < ts(addDays(d, 1)) && e.end > ts(d)) {
          $c.append(evButton(e, 'bar').append($('<span class="ttl"></span>').text(e.title || t('cal_untitled')).append(evMarks(e))));
        }
      });
      $all.append($c);
    });
    var $scroll = $('<div class="m4w-cal-scroll" tabindex="0" role="region"></div>').attr('aria-label', t('cal_hours'));
    var $grid = $('<div class="m4w-cal-timegrid"></div>').css('height', HOUR * 24);
    var $hours = $('<div class="m4w-cal-hours" aria-hidden="true"></div>');
    for (var h = 0; h < 24; h++) $hours.append($('<div></div>').css('top', h * HOUR).text(h ? pad(h) + ':00' : ''));
    $grid.append($hours);
    days.forEach(function (d) {
      var $col = $('<div class="m4w-cal-col"></div>').attr('data-date', ymd(d)).toggleClass('today', sameDay(d, today));
      var dayStart = ts(d), dayEnd = ts(addDays(d, 1));
      var list = layoutDay(S.events.filter(function (e) { return !e.all_day && e.end - e.start < 86400 && e.start < dayEnd && e.end > dayStart; }).map(function (e) { return $.extend({}, e); }));
      list.forEach(function (e) {
        var s = Math.max(e.start, dayStart), en = Math.min(Math.max(e.end, e.start + 900), dayEnd);
        var top = (s - dayStart) / 3600 * HOUR, height = Math.max(18, (en - s) / 3600 * HOUR - 2);
        var $e = evButton(e, 'block').css({ top: top, height: height, left: (e._col / e._cols * 100) + '%', width: 'calc(' + (100 / e._cols) + '% - 3px)' });
        $e.append($('<span class="ttl"></span>').text(e.title || t('cal_untitled')).append(evMarks(e)));
        $e.append($('<span class="tm"></span>').text(time(new Date(e.start * 1000)) + ' – ' + time(new Date(e.end * 1000))));
        if (e.location) $e.append($('<span class="loc"></span>').text(e.location));
        if (e.writable && e.end <= dayEnd) $e.append('<span class="m4w-cal-resize" aria-hidden="true"></span>');
        $col.append($e);
      });
      if (sameDay(d, today)) {
        var now = new Date();
        $col.append($('<div class="m4w-cal-now" aria-hidden="true"></div>').css('top', (now.getHours() + now.getMinutes() / 60) * HOUR));
      }
      $grid.append($col);
    });
    $scroll.append($grid);
    $wrap.append($head, $all, $scroll);
    $b.append($wrap);
    // Same days shown again (refresh, event saved): the position stays where the person left it.
    // Otherwise: today on screen → the current hour, a third down; else the first event (7:00 at the latest).
    var key = S.view + ymd(r[0]);
    if (S.scroll && S.scroll.key === key) { $scroll.scrollTop(S.scroll.top); }
    else if (days.some(function (d) { return sameDay(d, today); })) {
      $scroll.scrollTop(Math.max(0, (today.getHours() + today.getMinutes() / 60) * HOUR - $scroll.innerHeight() / 3));
    } else {
      var first = S.events.filter(function (e) { return !e.all_day; }).map(function (e) { return new Date(e.start * 1000).getHours(); });
      $scroll.scrollTop(Math.max(0, (first.length ? Math.min(7, Math.min.apply(null, first)) : 7) * HOUR - 8));
    }
    S.scroll = { key: key, top: $scroll.scrollTop() };
    $scroll.on('scroll', function () { S.scroll = { key: key, top: this.scrollTop }; });
  }

  // ---------------------------------------------------------------- list view
  function renderList($b) {
    var $l = $('<div class="m4w-cal-list"></div>'), last = '';
    if (!S.events.length) { $b.append($('<div class="m4w-empty py-5"></div>').append('<div class="m4w-empty-icon"><i class="bi bi-calendar-check"></i></div>').append($('<h3></h3>').text(t('cal_nothing')))); return; }
    S.events.forEach(function (e) {
      var d = new Date(e.start * 1000), key = ymd(d);
      if (key !== last) { $l.append($('<h2 class="m4w-cal-list-day"></h2>').text(fmt(d, { weekday: 'long', day: 'numeric', month: 'long' }))); last = key; }
      var $e = evButton(e, 'row');
      $e.attr('draggable', 'false');
      $e.append($('<span class="tm"></span>').text(e.all_day ? t('cal_all_day') : time(d) + ' – ' + time(new Date(e.end * 1000))));
      $e.append($('<span class="ttl"></span>').text(e.title || t('cal_untitled')).append(evMarks(e)));
      if (e.location) $e.append($('<span class="loc"></span>').append('<i class="bi bi-geo-alt me-1"></i>').append(document.createTextNode(e.location)));
      if (e.my_partstat === 'NEEDS-ACTION') $e.append($('<span class="badge badge-soft-warning ms-2"></span>').text(t('cal_to_answer')));
      $l.append($e);
    });
    $b.append($l);
  }

  // ---------------------------------------------------------------- sidebar
  function renderMini() {
    var $m = $('[data-cal-mini]').empty(), d = S.miniDate || S.date, first = new Date(d.getFullYear(), d.getMonth(), 1), from = startOfWeek(first), today = new Date();
    var $h = $('<div class="m4w-cal-mini-head"></div>');
    $h.append($('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-mini="-1"><i class="bi bi-chevron-left"></i></button>').attr('aria-label', t('cal_prev_month')));
    $h.append($('<span class="fw-semibold"></span>').text(fmt(first, { month: 'long', year: 'numeric' })));
    $h.append($('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-mini="1"><i class="bi bi-chevron-right"></i></button>').attr('aria-label', t('cal_next_month')));
    var $g = $('<div class="m4w-cal-mini-grid"></div>');
    for (var i = 0; i < 7; i++) $g.append($('<span class="wd" aria-hidden="true"></span>').text(fmt(addDays(from, i), { weekday: 'narrow' })));
    var r = range();
    for (var j = 0; j < 42; j++) {
      var day = addDays(from, j);
      $g.append($('<button type="button"></button>').text(day.getDate()).attr('data-goto', ymd(day)).attr('aria-label', fmt(day, { weekday: 'long', day: 'numeric', month: 'long' }))
        .toggleClass('other', day.getMonth() !== first.getMonth()).toggleClass('today', sameDay(day, today)).toggleClass('in', day >= r[0] && day < r[1] && S.view !== 'month' && S.view !== 'list'));
    }
    $m.append($h, $g);
  }

  function renderLists() {
    var $l = $('[data-cal-lists]').empty();
    var groups = [['mine', t('cal_mine')], ['shared', t('cal_shared')], ['room', t('cal_rooms')]];
    groups.forEach(function (g) {
      var list = S.calendars.filter(function (c) { return g[0] === 'room' ? c.kind === 'room' : (g[0] === 'mine' ? c.mine && c.kind !== 'room' : !c.mine && c.kind !== 'room'); });
      if (!list.length) return;
      $l.append($('<div class="m4w-nav-title"></div>').text(g[1]));
      var $ul = $('<ul class="m4w-cal-callist"></ul>');
      list.forEach(function (c) {
        var id = 'cal-v-' + c.id;
        var $li = $('<li></li>').css('--ev', c.color);
        $li.append($('<input type="checkbox" class="form-check-input">').attr('id', id).prop('checked', !S.hidden[c.id]).attr('data-cal-toggle', c.id));
        $li.append($('<label class="text-truncate"></label>').attr('for', id).text(c.name).attr('title', c.owner ? t('cal_owner', { name: c.owner }) : c.name));
        if (c.level === 'read') $li.append($('<i class="bi bi-eye small text-muted"></i>').attr('title', t('cal_readonly')));
        var $dd = $('<div class="dropdown ms-auto"></div>');
        $dd.append($('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots"></i></button>').attr('aria-label', t('cal_options', { name: c.name })));
        var $menu = $('<ul class="dropdown-menu dropdown-menu-end"></ul>');
        if (c.level === 'owner') $menu.append($('<li><button class="dropdown-item" type="button"><i class="bi bi-pencil me-2"></i></button></li>').find('button').attr('data-cal-edit', c.id).append(document.createTextNode(c.kind === 'room' ? t('cal_edit_room') : t('cal_edit_share'))).end());
        $menu.append($('<li><button class="dropdown-item" type="button" data-cal-only><i class="bi bi-eye me-2"></i></button></li>').find('button').attr('data-cal-only', c.id).append(document.createTextNode(t('cal_only'))).end());
        $menu.append($('<li><a class="dropdown-item"><i class="bi bi-download me-2"></i></a></li>').find('a').attr('href', M4W.url('calendar/export/' + c.id)).append(document.createTextNode(t('cal_export'))).end());
        if (c.level !== 'read') $menu.append($('<li><button class="dropdown-item" type="button"><i class="bi bi-upload me-2"></i></button></li>').find('button').attr('data-cal-import', c.id).append(document.createTextNode(t('cal_import'))).end());
        $dd.append($menu);
        $li.append($dd);
        $ul.append($li);
      });
      $l.append($ul);
    });

  }

  // ---------------------------------------------------------------- navigation
  function go(delta) {
    if (S.view === 'day') S.date = addDays(S.date, delta);
    else if (S.view === 'week') S.date = addDays(S.date, 7 * delta);
    else if (S.view === 'list') S.date = addDays(S.date, 30 * delta);
    else S.date = new Date(S.date.getFullYear(), S.date.getMonth() + delta, 1);
    S.miniDate = null;
    load();
  }
  $(document).on('click', '[data-cal-prev]', function () { go(-1); });
  $(document).on('click', '[data-cal-next]', function () { go(1); });
  $(document).on('click', '[data-cal-today]', function () { S.date = startOfDay(new Date()); S.miniDate = null; S.scroll = null; load(); });
  $(document).on('click', '[data-cal-view]', function () { S.view = $(this).data('cal-view'); saveState(); load(); });
  $(document).on('click', '[data-goto]', function (ev) {
    ev.stopPropagation();
    var p = String($(this).data('goto')).split('-');
    S.date = new Date(+p[0], +p[1] - 1, +p[2]); S.miniDate = null;
    if ($(this).data('goto-view')) S.view = $(this).data('goto-view');
    else if ($(this).closest('[data-cal-mini]').length && S.view === 'month') S.view = 'day';
    saveState(); load();
  });
  $(document).on('click', '[data-mini]', function () {
    var d = S.miniDate || S.date; S.miniDate = new Date(d.getFullYear(), d.getMonth() + +$(this).data('mini'), 1); renderMini();
  });
  $(document).on('change', '[data-cal-toggle]', function () {
    var id = $(this).data('cal-toggle'); if (this.checked) delete S.hidden[id]; else S.hidden[id] = 1; saveState(); load();
  });
  $(document).on('click', '[data-cal-only]', function () {
    var only = +$(this).data('cal-only'); S.hidden = {};
    S.calendars.forEach(function (c) { if (+c.id !== only) S.hidden[c.id] = 1; });
    saveState(); renderLists(); load();
  });
  $(document).on('keydown', function (ev) {
    if ($(ev.target).is('input, textarea, select, [contenteditable]') || ev.ctrlKey || ev.metaKey || ev.altKey || $('.modal.show').length) return;
    var k = ev.key.toLowerCase();
    var map = { j: 'day', d: 'day', s: 'week', w: 'week', m: 'month', l: 'list' };
    if (map[k]) { S.view = map[k]; saveState(); load(); }
    else if (k === 't' || k === 'a') $('[data-cal-today]').trigger('click');
    else if (k === 'n' || k === 'c') openNew();
    else if (ev.key === 'ArrowLeft' || k === 'p') go(-1);
    else if (ev.key === 'ArrowRight') go(1);
    else return;
    ev.preventDefault();
  });

  // ---------------------------------------------------------------- creating by clicking the grid
  $(document).on('click', '.m4w-cal-col', function (ev) {
    if ($(ev.target).closest('.m4w-cal-ev').length || S.dragged) return;
    var off = ev.pageY - $(this).offset().top, mins = Math.floor(off / HOUR * 2) * 30;
    var p = String($(this).data('date')).split('-'), s = new Date(+p[0], +p[1] - 1, +p[2], 0, mins);
    openNew(s, new Date(s.getTime() + 3600000), false);
  });
  $(document).on('click', '.m4w-cal-day, .m4w-cal-allday-cell', function (ev) {
    if ($(ev.target).closest('.m4w-cal-ev, button').length || S.dragged) return;
    var p = String($(this).data('date')).split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
    if ($(this).hasClass('m4w-cal-allday-cell')) openNew(d, d, true);
    else openNew(new Date(+p[0], +p[1] - 1, +p[2], 9), new Date(+p[0], +p[1] - 1, +p[2], 10), false);
  });
  $(document).on('click', '[data-cal-new]', function () { openNew(); });

  // ---------------------------------------------------------------- drag and drop
  var drag = null;
  $(document).on('dragstart', '.m4w-cal-ev', function (ev) {
    var e = $(this).data('ev');
    if (!e || !e.writable) { ev.preventDefault(); return; }
    var rect = this.getBoundingClientRect();
    drag = { ev: e, grabY: ev.originalEvent.clientY - rect.top };
    ev.originalEvent.dataTransfer.effectAllowed = 'move';
    try { ev.originalEvent.dataTransfer.setData('text/plain', e.title || ''); } catch (x) { /* ignore */ }
  });
  $(document).on('dragover', '.m4w-cal-col, .m4w-cal-day, .m4w-cal-allday-cell', function (ev) { if (drag) { ev.preventDefault(); $(this).addClass('drop'); } });
  $(document).on('dragleave', '.m4w-cal-col, .m4w-cal-day, .m4w-cal-allday-cell', function () { $(this).removeClass('drop'); });
  $(document).on('drop', '.m4w-cal-col, .m4w-cal-day, .m4w-cal-allday-cell', function (ev) {
    ev.preventDefault(); $(this).removeClass('drop');
    if (!drag) return;
    var e = drag.ev, p = String($(this).data('date')).split('-'), day = new Date(+p[0], +p[1] - 1, +p[2]), s = new Date(e.start * 1000), target;
    if ($(this).hasClass('m4w-cal-col') && !e.all_day) {
      var y = ev.originalEvent.pageY - $(this).offset().top - drag.grabY;
      var mins = Math.max(0, Math.round(y / HOUR * 4) * 15);
      target = new Date(+p[0], +p[1] - 1, +p[2], 0, mins);
    } else {
      target = new Date(+p[0], +p[1] - 1, +p[2], s.getHours(), s.getMinutes());
    }
    var delta = ts(target) - e.start;
    if (e.all_day) delta = Math.round((ts(day) - ts(startOfDay(s))) / 86400) * 86400;
    drag = null;
    if (delta) move(e, delta, 0);
  });
  $(document).on('dragend', function () { drag = null; $('.drop').removeClass('drop'); });

  // Resizing (pointer events: works with a mouse, a pen or a finger).
  $(document).on('pointerdown', '.m4w-cal-resize', function (ev) {
    ev.preventDefault(); ev.stopPropagation();
    var $e = $(this).closest('.m4w-cal-ev'), e = $e.data('ev'), y0 = ev.clientY, h0 = $e.outerHeight();
    $e.addClass('resizing');
    $(document).on('pointermove.calrs', function (m) { $e.css('height', Math.max(16, h0 + m.clientY - y0)); });
    $(document).one('pointerup', function (u) {
      $(document).off('pointermove.calrs'); $e.removeClass('resizing');
      var stretch = Math.round((u.clientY - y0) / HOUR * 4) * 900;
      S.dragged = true; setTimeout(function () { S.dragged = false; }, 50);
      if (stretch && e.end + stretch > e.start) move(e, 0, stretch); else render();
    });
  });

  function move(e, delta, stretch) {
    scopeFor(e, t('cal_move_which')).then(function (scope) {
      if (!scope) { render(); return; }
      M4W.post('api/calendar/event/move', { calendar_id: e.calendar_id, uri: e.uri, delta: delta, stretch: stretch, scope: scope, occurrence: e.recurrence_id || e.start })
        .done(function (r) { if (r.sent) M4W.toast(t('cal_sent', { n: r.sent }), 'success'); load(); })
        .fail(function () { load(); });
    });
  }

  /** Recurring events: this occurrence or the whole series. Promise of 'one' | 'all' | null. */
  function scopeFor(e, question) {
    var d = $.Deferred();
    if (!e.recurring && !isSeries(e)) { d.resolve('all'); return d.promise(); }
    var $m = $('<div class="modal fade" tabindex="-1" aria-labelledby="cal-scope-t" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title fs-6" id="cal-scope-t"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
      + '<div class="modal-body d-grid gap-2"><button type="button" class="btn btn-light" data-s="one"></button><button type="button" class="btn btn-light" data-s="all"></button></div></div></div></div>');
    $m.find('h2').text(question); $m.find('.btn-close').attr('aria-label', t('close'));
    $m.find('[data-s=one]').text(t('cal_scope_one')); $m.find('[data-s=all]').text(t('cal_scope_all'));
    var res = null;
    $m.on('click', '[data-s]', function () { res = $(this).data('s'); bootstrap.Modal.getInstance($m[0]).hide(); });
    $m.on('hidden.bs.modal', function () { $m.remove(); d.resolve(res); });
    $m.appendTo(document.body); bootstrap.Modal.getOrCreateInstance($m[0]).show();
    return d.promise();
  }
  function isSeries(e) { return S.events.filter(function (x) { return x.uri === e.uri && x.calendar_id === e.calendar_id; }).length > 1 || !!e._series; }

  // ---------------------------------------------------------------- event form
  var $modal = $('#cal-event'), $f = $('[data-cal-form]'), F = { attendees: [] };

  function writableCalendars() { return S.calendars.filter(function (c) { return c.level !== 'read'; }); }
  function fillCalendarSelect(selected) {
    var $s = $f.find('[name=calendar_id]').empty();
    writableCalendars().forEach(function (c) { $s.append($('<option></option>').val(c.id).text(c.name + (c.owner ? ' (' + c.owner + ')' : ''))); });
    $s.val(selected || (writableCalendars()[0] || {}).id);
  }
  function setAllDay(on) {
    $f.find('[name=all_day]').prop('checked', on);
    $f.find('[name=start_time], [name=end_time]').toggleClass('d-none', on).prop('disabled', on);
  }
  function renderAttendees() {
    var $a = $f.find('[data-cal-attendees]').empty();
    F.attendees.forEach(function (a, i) {
      var icon = { ACCEPTED: 'check-circle-fill text-success', DECLINED: 'x-circle-fill text-danger', TENTATIVE: 'question-circle-fill text-warning' }[a.partstat] || 'circle text-muted';
      var $c = $('<span class="m4w-cal-chip"></span>').attr('title', t('cal_ps_' + String(a.partstat || 'NEEDS-ACTION').toLowerCase().replace('-', '_')));
      $c.append($('<i></i>').addClass('bi bi-' + icon)).append(document.createTextNode(' ' + (a.name || a.email)));
      if (F.organizer) $c.append($('<button type="button" class="btn-close btn-close-sm" data-rm></button>').attr('data-rm', i).attr('aria-label', t('cal_remove_attendee', { name: a.name || a.email })));
      $a.append($c);
    });
    checkBusy();
  }
  var checkBusy = M4W.debounce(function () {
    var $b = $f.find('[data-cal-busy]').addClass('d-none').empty();
    var internal = F.attendees.filter(function (a) { return BOOT.people.some(function (p) { return p.email.toLowerCase() === a.email; }); });
    var times = formTimes();
    if (!internal.length || !times || times.allDay) return;
    M4W.get('api/calendar/busy', { emails: internal.map(function (a) { return a.email; }).join(','), from: ts(times.start) - 1, to: ts(times.end) + 1 }, { silent: true, quiet: true }).done(function (r) {
      var clash = [];
      $.each(r.busy || {}, function (email, list) {
        if (list.some(function (b) { return b.start < ts(times.end) && b.end > ts(times.start) && !(F.uid && b.uid === F.uid); })) {
          var p = internal.filter(function (a) { return a.email === email; })[0]; clash.push(p ? p.name || p.email : email);
        }
      });
      if (clash.length) $b.removeClass('d-none').append('<i class="bi bi-exclamation-triangle me-1"></i>').append(document.createTextNode(t('cal_busy', { names: clash.join(', ') })));
    });
  }, 300);
  function formTimes() {
    var sd = $f.find('[name=start_date]').val(), ed = $f.find('[name=end_date]').val();
    if (!sd || !ed) return null;
    var all = $f.find('[name=all_day]').is(':checked');
    return { allDay: all, start: parseLocal(sd, all ? '00:00' : $f.find('[name=start_time]').val()), end: parseLocal(ed, all ? '23:59' : $f.find('[name=end_time]').val()) };
  }

  function openForm(data) {
    F = { attendees: (data.attendees || []).slice(), uri: data.uri || '', original: data.calendar_id || null, occurrence: data.occurrence || null, recurring: !!data.recurring,
      organizer: data.i_organize !== false, uid: data.uid || '', writable: data.writable !== false, partstat: data.my_partstat || '',
      room: +data.room_id || 0, roomName: data.room_name || '', roomStatus: data.room_status || '' };
    $f[0].reset();
    $('#cal-event-title').text(F.uri ? t('cal_edit_event') : t('cal_new_event'));
    fillCalendarSelect(data.calendar_id);
    $f.find('[name=title]').val(data.title || '');
    $f.find('[name=location]').val(data.location || '');
    $f.find('[name=description]').val(data.description || '');
    var s = data.start, e = data.end;
    $f.find('[name=start_date]').val(s.slice(0, 10)); $f.find('[name=end_date]').val(e.slice(0, 10));
    $f.find('[name=start_time]').val(s.length > 10 ? s.slice(11, 16) : '09:00'); $f.find('[name=end_time]').val(e.length > 10 ? e.slice(11, 16) : '10:00');
    setAllDay(!!data.all_day);
    $f.find('[name=repeat]').val(data.repeat || 'none').find('option[value=custom]').prop('hidden', data.repeat !== 'custom');
    $f.find('[name=repeat_until]').val(data.repeat_until || '');
    $f.find('[data-cal-until]').toggle((data.repeat || 'none') !== 'none');
    $f.find('[name=reminder]').val(String(data.reminder === undefined ? 15 : data.reminder));
    if (!$f.find('[name=reminder]').val()) $f.find('[name=reminder]').val('15');
    renderAttendees();
    // An invitation from someone else: answer it, details read-only.
    var guest = !!F.uri && !F.organizer;
    var $inv = $f.find('[data-cal-invite]').addClass('d-none').empty();
    if (guest) {
      $inv.removeClass('d-none').append('<i class="bi bi-envelope-paper me-1"></i>').append(document.createTextNode(t('cal_invited_by', { name: data.organizer_name || data.organizer })
        + (F.partstat ? ' — ' + t('cal_ps_' + F.partstat.toLowerCase().replace('-', '_')) : '')));
    }
    $f.find('input, select, textarea').not('[name=calendar_id], [name=reminder]').prop('readonly', guest).filter('select, [type=checkbox], [type=date], [type=time]').prop('disabled', guest || !F.writable);
    $f.find('[data-cal-attendee-input]').closest('.position-relative').toggleClass('d-none', guest);
    $f.find('[data-cal-answer]').toggleClass('d-none', !guest || !F.partstat);
    $f.find('[data-cal-answer] [data-partstat]').each(function () { $(this).toggleClass('active', $(this).data('partstat') === F.partstat).attr('aria-pressed', $(this).data('partstat') === F.partstat ? 'true' : 'false'); });
    $f.find('[data-cal-delete]').toggleClass('d-none', !F.uri || !F.writable);
    $f.find('[data-cal-save]').toggleClass('d-none', !F.writable);
    if (!F.writable) $f.find('input, select, textarea').prop('disabled', true);
    if (data.all_day) setAllDay(true);
    $f.find('[data-cal-roomrow]').toggleClass('d-none', guest || !F.writable);
    // Online meeting: the providers enabled for this person, and the link once created.
    var $ms = $f.find('[name=meeting]'), meets = BOOT.meetings || {};
    $ms.find('option:not([value=""])').remove();
    $.each(meets, function (k, label) { $ms.append($('<option></option>').val(k).text(label)); });
    if (data.meeting && !meets[data.meeting]) $ms.append($('<option></option>').val(data.meeting).text(data.meeting));
    $ms.val(data.meeting || '').prop('disabled', guest || !F.writable);
    F.meetingUrl = data.meeting_url || '';
    F.meetingJoin = data.meeting_join || F.meetingUrl;
    renderMeetBlock(data);
    $f.find('[data-cal-join]').toggleClass('d-none', !F.meetingUrl).attr('href', F.meetingJoin || F.meetingUrl || '#');
    $f.find('[data-cal-copyjoin]').toggleClass('d-none', !F.meetingUrl);
    $f.find('[data-cal-meetrow]').toggleClass('d-none', !Object.keys(meets).length && !F.meetingUrl);
    refreshRooms(true);
    // An existing event opens on its summary (when, where, who, what); a new one on the form.
    F.data = data;
    $modal.find('[data-cal-tabs]').toggleClass('d-none', !F.uri);
    if (F.uri) renderSummary(data);
    setTab(F.uri ? 'summary' : 'form');
    bootstrap.Modal.getOrCreateInstance($modal[0]).show();
  }
  $modal.on('shown.bs.modal', function () { if (!F.uri) $f.find('[name=title]').trigger('focus'); });

  // ---------------------------------------------------------------- online meeting block
  // Written by the application into the invitation, under the description: shown here as it
  // will be sent, and never editable (no typo can slip into a link, an ID or a code).
  function renderMeetBlock(d) {
    var $b = $f.find('[data-cal-meetblock]').empty(), chosen = $f.find('[name=meeting]').val() || '';
    var meets = BOOT.meetings || {};
    if (!chosen) { $b.addClass('d-none'); return; }
    var label = meets[chosen] || chosen;
    $b.removeClass('d-none').append($('<div class="m4w-meet-block-head"></div>').append('<i class="bi bi-lock me-1" aria-hidden="true"></i>').append(document.createTextNode(t('cal_meet_block', { provider: label }))));
    if (d && d.meeting === chosen && d.meeting_url) {
      var $dl = $('<dl class="mb-1"></dl>');
      $dl.append($('<dt></dt>').text(t('cal_meet_link'))).append($('<dd class="m4w-code"></dd>').append($('<a target="_blank" rel="noopener noreferrer"></a>').attr('href', d.meeting_join || d.meeting_url).text(d.meeting_url)));
      (d.meeting_details || []).forEach(function (r) { $dl.append($('<dt></dt>').text(r[0])).append($('<dd class="m4w-code"></dd>').text(r[1])); });
      $b.append($dl);
      if (d.meeting_howto) $b.append($('<div class="small text-muted"></div>').text(d.meeting_howto));
    } else {
      $b.append($('<div class="small text-muted"></div>').text(t('cal_meet_pending', { provider: label })));
    }
  }
  $f.on('change', '[name=meeting]', function () { renderMeetBlock(F.data || {}); });

  // ---------------------------------------------------------------- summary tab
  function setTab(name) {
    $modal.find('[data-cal-pane]').each(function () { this.hidden = $(this).data('cal-pane') !== name; });
    $modal.find('[data-cal-tabs] [data-cal-tab]').each(function () { var on = $(this).data('cal-tab') === name; $(this).toggleClass('active', on).attr({ 'aria-selected': on ? 'true' : 'false', tabindex: on ? '0' : '-1' }); });
    var summary = name === 'summary';
    $f.find('[data-cal-save]').toggleClass('d-none', summary || !F.writable);
    $f.find('[data-cal-editbtn]').toggleClass('d-none', !summary || !F.writable || !F.organizer);
    $f.find('[data-cal-cancel]').text(summary ? t('close') : t('cancel'));
    $('#cal-event-title').text(!F.uri ? t('cal_new_event') : (summary || !F.writable || !F.organizer ? t('cal_event') : t('cal_edit_event')));
  }
  $modal.on('click', '[data-cal-tab]', function () { setTab($(this).data('cal-tab')); if ($(this).data('cal-tab') === 'form' && F.writable && F.organizer) $f.find('[name=title]').trigger('focus'); });
  $modal.on('keydown', '[data-cal-tabs] [data-cal-tab]', function (ev) {
    if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
    var next = $(this).data('cal-tab') === 'summary' ? 'form' : 'summary';
    setTab(next); $modal.find('[data-cal-tabs] [data-cal-tab="' + next + '"]').trigger('focus');
  });
  // The form was changed: the summary follows.
  $f.on('change input', 'input, select, textarea', M4W.debounce(function () { if (F.uri) renderSummary(formSnapshot()); }, 200));
  function formSnapshot() {
    var all = $f.find('[name=all_day]').is(':checked');
    return $.extend({}, F.data, {
      title: $f.find('[name=title]').val(), location: $f.find('[name=location]').val(), description: $f.find('[name=description]').val(), all_day: all,
      start: $f.find('[name=start_date]').val() + (all ? '' : ' ' + $f.find('[name=start_time]').val()), end: $f.find('[name=end_date]').val() + (all ? '' : ' ' + $f.find('[name=end_time]').val()),
      repeat: $f.find('[name=repeat]').val(), repeat_until: $f.find('[name=repeat_until]').val(), reminder: +$f.find('[name=reminder]').val(),
      calendar_id: +$f.find('[name=calendar_id]').val(), attendees: F.attendees,
      room_name: +$f.find('[name=room_id]').val() ? $f.find('[name=room_id] option:selected').text().split(' · ')[0] : (F.data.room_name || '')
    });
  }
  function duration(min) {
    var d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60, out = [];
    if (d) out.push(t('cal_n_days', { n: d }));
    if (h) out.push(h + ' h');
    if (m) out.push(m + ' min');
    return out.join(' ');
  }
  function renderSummary(d) {
    var $p = $modal.find('[data-cal-pane=summary]').empty();
    var cal = calById(d.calendar_id) || {}, all = !!d.all_day;
    var s = parseLocal(d.start.slice(0, 10), all ? '00:00' : d.start.slice(11, 16)), e = parseLocal(d.end.slice(0, 10), all ? '00:00' : d.end.slice(11, 16));
    var row = function (icon, label, $content) {
      return $('<div class="m4w-cal-sum-row"></div>').append($('<i class="bi" aria-hidden="true"></i>').addClass('bi-' + icon))
        .append($('<div class="min-w-0 flex-grow-1"></div>').append($('<div class="m4w-cal-sum-label"></div>').text(label)).append($content));
    };
    // What
    var $head = $('<div class="m4w-cal-sum-head"></div>').css('--ev', cal.color || 'var(--m4w-primary)');
    $head.append($('<h3 class="m4w-cal-sum-title"></h3>').text(d.title || t('cal_untitled')));
    var meta = $('<div class="small text-muted"></div>').append($('<span class="m4w-cal-sum-cal"></span>').text(cal.name || ''));
    if (d.status === 'CANCELLED') meta.append(' · ').append($('<span class="badge badge-soft-danger"></span>').text(t('cal_cancelled')));
    var now = Date.now() / 1000;
    if (ts(s) <= now && ts(e) > now) meta.append(' · ').append($('<span class="badge badge-soft-success"></span>').text(t('cal_in_progress')));
    $head.append(meta);
    $p.append($head);
    if (!F.organizer && F.uri) {
      $p.append($('<div class="alert alert-info py-2 small mb-3" role="status"></div>').append('<i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>')
        .append(document.createTextNode(t('cal_invited_by', { name: d.organizer_name || d.organizer }) + (F.partstat ? ' — ' + t('cal_ps_' + F.partstat.toLowerCase().replace('-', '_')) : ''))));
    }
    // When
    var when;
    if (all) {
      var last = addDays(e, sameDay(s, e) ? 0 : -1);
      when = sameDay(s, last) ? fmt(s, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + ' · ' + t('cal_all_day')
        : t('cal_from_to', { from: fmt(s, { weekday: 'short', day: 'numeric', month: 'long' }), to: fmt(last, { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }) }) + ' · ' + t('cal_all_day');
    } else if (sameDay(s, e)) {
      when = fmt(s, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + ' · ' + time(s) + ' – ' + time(e);
    } else {
      when = fmt(s, { weekday: 'short', day: 'numeric', month: 'long' }) + ' ' + time(s) + ' → ' + fmt(e, { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }) + ' ' + time(e);
    }
    var $when = $('<div></div>').append($('<div class="fw-semibold text-capitalize-first"></div>').text(when));
    var extra = [];
    if (!all) extra.push(duration(Math.round((e - s) / 60000)));
    if (d.repeat && d.repeat !== 'none') extra.push(t('cal_repeat_' + d.repeat).replace(/^./, function (c) { return c.toLowerCase(); }) + (d.repeat_until ? ' ' + t('cal_until', { date: fmt(parseLocal(d.repeat_until), { day: 'numeric', month: 'long', year: 'numeric' }) }) : ''));
    if (d.reminder >= 0) extra.push(t('cal_reminder_before', { d: d.reminder ? duration(d.reminder) : t('cal_at_start') }));
    if (extra.length) $when.append($('<div class="small text-muted"></div>').text(extra.join(' · ')));
    $p.append(row('clock', t('cal_when'), $when));
    // Where
    var $where = $('<div class="d-flex flex-column gap-1"></div>'), any = false;
    if (d.room_name) {
      any = true;
      var st = d.room_status === 'pending' ? ['warning', t('cal_room_st_pending')] : (d.room_status === 'approved' || d.room_status === 'confirmed' ? ['success', t('cal_room_st_ok')] : (d.room_status === 'declined' ? ['danger', t('cal_room_st_declined')] : null));
      $where.append($('<div></div>').append('<i class="bi bi-door-open me-1" aria-hidden="true"></i>').append($('<span class="fw-semibold"></span>').text(d.room_name)).append(st ? $('<span class="badge ms-2"></span>').addClass('badge-soft-' + st[0]).text(st[1]) : ''));
    }
    if (d.location && d.location !== d.room_name) { any = true; $where.append($('<div class="fw-semibold"></div>').text(d.location)); }
    if (d.meeting_url) {
      any = true;
      $where.append($('<div class="d-flex flex-wrap align-items-center gap-2"></div>')
        .append($('<a class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer"></a>').attr('href', d.meeting_join || d.meeting_url).append('<i class="bi bi-camera-video me-1"></i>').append(document.createTextNode(t('cal_join_with', { name: (BOOT.meetings || {})[d.meeting] || d.meeting || '' }).trim())))
        .append($('<button type="button" class="btn btn-ghost btn-sm" data-cal-copyjoin></button>').append('<i class="bi bi-clipboard me-1"></i>').append(document.createTextNode(t('cal_copy_link')))));
    }
    if (d.meeting_url && (d.meeting_details || []).length) {
      var $md = $('<dl class="m4w-meet-dl small mb-0"></dl>');
      d.meeting_details.forEach(function (r) { $md.append($('<dt></dt>').text(r[0])).append($('<dd class="m4w-code"></dd>').text(r[1])); });
      $where.append($md);
    }
    if (!any) $where.append($('<span class="text-muted"></span>').text(t('cal_no_place')));
    $p.append(row('geo-alt', t('cal_where'), $where));
    // Who
    var $who = $('<div></div>'), people = $('<ul class="m4w-cal-people list-unstyled mb-0"></ul>');
    var orgEmail = d.organizer || BOOT.me.email, orgName = d.organizer_name || (orgEmail === BOOT.me.email ? BOOT.me.name : '');
    var person = function (name, email, tag, ps) {
      var icon = { ACCEPTED: 'check-circle-fill text-success', DECLINED: 'x-circle-fill text-danger', TENTATIVE: 'question-circle-fill text-warning' }[ps] || (ps ? 'clock text-muted' : '');
      var $li = $('<li></li>').append(M4W.avatar(name || email, email));
      $li.append($('<div class="min-w-0 flex-grow-1"></div>').append($('<div class="text-truncate fw-medium"></div>').text(name || email)).append($('<div class="small text-muted text-truncate"></div>').text(tag || email)));
      if (icon) $li.append($('<i class="bi" role="img"></i>').addClass('bi-' + icon).attr({ title: t('cal_ps_' + String(ps).toLowerCase().replace('-', '_')), 'aria-label': t('cal_ps_' + String(ps).toLowerCase().replace('-', '_')) }));
      return $li;
    };
    people.append(person(orgName, orgEmail, t('cal_organizer') + (orgEmail === BOOT.me.email ? ' · ' + t('cal_you') : '')));
    var counts = { ACCEPTED: 0, TENTATIVE: 0, DECLINED: 0, 'NEEDS-ACTION': 0 };
    (d.attendees || []).forEach(function (a) {
      var ps = a.partstat || 'NEEDS-ACTION'; counts[ps] = (counts[ps] || 0) + 1;
      people.append(person(a.name, a.email, a.email === BOOT.me.email ? t('cal_you') : (a.name ? a.email : ''), ps));
    });
    if ((d.attendees || []).length) {
      var parts = [];
      if (counts.ACCEPTED) parts.push(t('cal_n_accepted', { n: counts.ACCEPTED }));
      if (counts.TENTATIVE) parts.push(t('cal_n_tentative', { n: counts.TENTATIVE }));
      if (counts.DECLINED) parts.push(t('cal_n_declined', { n: counts.DECLINED }));
      if (counts['NEEDS-ACTION']) parts.push(t('cal_n_waiting', { n: counts['NEEDS-ACTION'] }));
      $who.append($('<div class="small mb-2"></div>').text(t('cal_n_guests', { n: d.attendees.length }) + ' — ' + parts.join(' · ')));
    }
    $who.append(people);
    $p.append(row('people', t('cal_who'), $who));
    // Notes
    if (d.description) $p.append(row('text-left', t('cal_notes'), $('<div class="m4w-cal-sum-notes"></div>').text(d.description)));
  }

  /** Rooms offered for the slot: only the free ones, open at that time (every occurrence). */
  var roomsReq = null;
  function refreshRooms(first) {
    var $s = $f.find('[name=room_id]'), $h = $f.find('[data-cal-roomhelp]');
    var all = $f.find('[name=all_day]').is(':checked'), sd = $f.find('[name=start_date]').val(), ed = $f.find('[name=end_date]').val();
    if (!sd || !ed) return;
    var want = first ? F.room : +$s.val() || 0;
    var q = { start: sd + (all ? '' : ' ' + ($f.find('[name=start_time]').val() || '09:00')), end: ed + (all ? '' : ' ' + ($f.find('[name=end_time]').val() || '10:00')),
      all_day: all ? 1 : 0, repeat: $f.find('[name=repeat]').val(), repeat_until: $f.find('[name=repeat_until]').val(), uid: F.uid };
    if (roomsReq && roomsReq.abort) roomsReq.abort();
    roomsReq = M4W.get('api/calendar/rooms', q, { silent: true, quiet: true }).done(function (r) {
      var list = r.rooms || [], free = list.filter(function (x) { return x.state === 'ok'; }), other = list.length - free.length;
      $s.find('option:not([value=0])').remove();
      free.forEach(function (x) {
        $s.append($('<option></option>').val(x.id).text(x.name + (x.capacity ? ' · ' + t('cal_room_capacity', { n: x.capacity }) : '') + (x.managed ? ' · ' + t('cal_room_managed') : '')).attr('title', x.details || ''));
      });
      var still = free.some(function (x) { return +x.id === want; });
      $s.val(still ? String(want) : '0');
      var help = '';
      if (want && !still) {
        var gone = list.filter(function (x) { return +x.id === want; })[0];
        help = t('cal_room_lost', { room: gone ? gone.name : F.roomName }) + (gone && gone.why ? ' ' + gone.why : '');
      } else if (want && still && first && F.roomStatus === 'pending') help = t('cal_room_pending');
      else if (want && still && first && F.roomStatus === 'approved') help = t('cal_room_approved');
      else if (!list.length) help = '';
      else if (!free.length) help = t('cal_room_none_free');
      else if (other) help = t('cal_room_hidden', { n: other });
      $h.text(help).toggleClass('text-warning-emphasis', !!(want && !still));
      $f.find('[data-cal-roomrow]').toggleClass('d-none', !list.length || !F.organizer || !F.writable);
    });
  }
  var refreshRoomsSoon = M4W.debounce(function () { refreshRooms(false); }, 250);
  $f.on('change', '[name=start_date], [name=start_time], [name=end_date], [name=end_time], [name=all_day], [name=repeat], [name=repeat_until]', refreshRoomsSoon);
  $f.on('click', '[data-cal-copyjoin]', function () {
    if (navigator.clipboard && F.meetingUrl) navigator.clipboard.writeText(F.meetingUrl).then(function () { M4W.toast(t('cal_link_copied'), 'success'); });
  });
  $f.on('change', '[name=room_id]', function () {
    var name = $(this).find('option:selected').text().split(' · ')[0], $loc = $f.find('[name=location]');
    if (+this.value && (!$loc.val() || $loc.val() === F.roomName)) { $loc.val(name); F.roomName = name; }
  });

  function openNew(start, end, allDay) {
    if (!writableCalendars().length) return;
    if (!start) { start = new Date(); start.setMinutes(start.getMinutes() < 30 ? 30 : 60, 0, 0); end = new Date(start.getTime() + 3600000); }
    var fmtD = function (d) { return allDay ? ymd(d) : ymd(d) + ' ' + hm(d); };
    var firstVisible = writableCalendars().filter(function (c) { return !S.hidden[c.id] && c.mine; })[0] || writableCalendars()[0];
    openForm({ start: fmtD(start), end: fmtD(end || start), all_day: !!allDay, calendar_id: firstVisible.id, reminder: 15, repeat: 'none' });
  }

  function openEvent(e) {
    M4W.get('api/calendar/event', { calendar_id: e.calendar_id, uri: e.uri, occurrence: e.recurrence_id || (e.recurring ? e.start : 0) || (isSeries(e) ? e.start : 0) }).done(function (r) {
      var d = r.event; d.occurrence = e.recurrence_id || e.start; d.recurring = d.recurring || e.recurring;
      openForm(d);
    });
  }
  $(document).on('click', '.m4w-cal-ev', function (ev) {
    ev.stopPropagation();
    if (S.dragged) return;
    var e = $(this).data('ev'); if (e) openEvent(e);
  });

  $f.on('change', '[name=all_day]', function () {
    var on = this.checked;
    setAllDay(on);
    checkBusy();
  });
  $f.on('change', '[name=start_date], [name=start_time]', function () {
    // Keep the duration when the start moves.
    var s = parseLocal($f.find('[name=start_date]').val(), $f.find('[name=start_time]').val() || '00:00');
    var old = $f.data('start') || s, en = parseLocal($f.find('[name=end_date]').val() || $f.find('[name=start_date]').val(), $f.find('[name=end_time]').val() || '00:00');
    var ne = new Date(en.getTime() + (s - old));
    if (!isNaN(ne)) { $f.find('[name=end_date]').val(ymd(ne)); $f.find('[name=end_time]').val(hm(ne)); }
    $f.data('start', s); checkBusy();
  });
  $f.on('focus', '[name=start_date], [name=start_time]', function () { $f.data('start', parseLocal($f.find('[name=start_date]').val(), $f.find('[name=start_time]').val() || '00:00')); });
  $f.on('change', '[name=end_date], [name=end_time]', checkBusy);
  $f.on('change', '[name=repeat]', function () { $f.find('[data-cal-until]').toggle($(this).val() !== 'none'); });

  // Attendees: suggestions from contacts and the directory.
  var $in = $f.find('[data-cal-attendee-input]'), $sg = $f.find('[data-cal-suggest]');
  function addAttendee(email, name) {
    email = String(email || '').trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email === BOOT.me.email || F.attendees.some(function (a) { return a.email === email; })) return false;
    F.attendees.push({ email: email, name: name || '', partstat: 'NEEDS-ACTION' }); renderAttendees(); return true;
  }
  $in.on('input', M4W.debounce(function () {
    var q = $in.val().trim(); $sg.removeClass('show').empty();
    if (q.length < 2) return;
    M4W.get('api/contacts/suggest', { q: q }, { silent: true, quiet: true }).done(function (r) {
      (r.items || []).slice(0, 8).forEach(function (it) {
        $sg.append($('<button type="button" class="dropdown-item"></button>').attr('data-email', it.email).attr('data-name', it.name)
          .append($('<span class="fw-medium"></span>').text(it.name || it.email)).append($('<span class="small text-muted ms-2"></span>').text(it.name ? it.email : '')));
      });
      $sg.toggleClass('show', !!(r.items || []).length);
    });
  }, 200));
  $in.on('keydown', function (ev) {
    if (ev.key === 'Enter' || ev.key === ',' || ev.key === ';') {
      ev.preventDefault();
      var $first = $sg.find('.dropdown-item').first();
      if ($sg.hasClass('show') && $first.length && !/@/.test($in.val())) $first.trigger('click');
      else if (addAttendee($in.val())) { $in.val(''); $sg.removeClass('show'); }
    } else if (ev.key === 'ArrowDown' && $sg.hasClass('show')) { ev.preventDefault(); $sg.find('.dropdown-item').first().trigger('focus'); }
    else if (ev.key === 'Escape' && $sg.hasClass('show')) { ev.stopPropagation(); $sg.removeClass('show'); }
  });
  $in.on('blur', function () { setTimeout(function () { if (!$sg.find(':focus').length) { if ($in.val().indexOf('@') > 0 && addAttendee($in.val())) $in.val(''); $sg.removeClass('show'); } }, 150); });
  $sg.on('click', '.dropdown-item', function () { addAttendee($(this).data('email'), $(this).data('name')); $in.val('').trigger('focus'); $sg.removeClass('show'); });
  $sg.on('keydown', '.dropdown-item', function (ev) {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); $(this).next().trigger('focus'); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); ($(this).prev().length ? $(this).prev() : $in).trigger('focus'); }
  });
  $f.on('click', '[data-rm]', function () { F.attendees.splice(+$(this).attr('data-rm'), 1); renderAttendees(); });

  $f.on('submit', function (ev) {
    ev.preventDefault();
    if ($in.val().indexOf('@') > 0) { addAttendee($in.val()); $in.val(''); }
    var title = $f.find('[name=title]').val().trim();
    if (!title) { $f.find('[name=title]').addClass('is-invalid').trigger('focus'); return; }
    $f.find('[name=title]').removeClass('is-invalid');
    var all = $f.find('[name=all_day]').is(':checked');
    var body = {
      calendar_id: +$f.find('[name=calendar_id]').val(), original_calendar_id: F.original, uri: F.uri, title: title,
      location: $f.find('[name=location]').val(), description: $f.find('[name=description]').val(), all_day: all,
      start: $f.find('[name=start_date]').val() + (all ? '' : ' ' + ($f.find('[name=start_time]').val() || '09:00')),
      end: $f.find('[name=end_date]').val() + (all ? '' : ' ' + ($f.find('[name=end_time]').val() || '10:00')),
      reminder: +$f.find('[name=reminder]').val(), attendees: F.attendees, occurrence: F.occurrence
    };
    if (F.organizer && !$f.find('[data-cal-roomrow]').hasClass('d-none')) body.room_id = +$f.find('[name=room_id]').val() || 0;
    if (F.organizer && !$f.find('[name=meeting]').prop('disabled')) body.meeting = $f.find('[name=meeting]').val() || '';
    var rep = $f.find('[name=repeat]').val();
    if (rep !== 'custom') { body.repeat = rep; body.repeat_until = rep === 'none' ? '' : $f.find('[name=repeat_until]').val(); }
    var send = function (scope) {
      if (!scope) return;
      body.scope = scope;
      if (scope === 'one') { delete body.repeat; delete body.repeat_until; }
      M4W.post('api/calendar/event', body).done(function (r) {
        bootstrap.Modal.getInstance($modal[0]).hide();
        M4W.toast((r.sent ? t('cal_saved_sent', { n: r.sent }) : t('cal_saved')) + (r.room_status === 'pending' ? ' ' + t('cal_room_pending') : ''), 'success');
        load();
      });
    };
    if (F.uri && F.recurring) scopeFor({ recurring: true }, t('cal_edit_which')).then(send); else send('all');
  });

  $f.on('click', '[data-cal-delete]', function () {
    var e = { recurring: F.recurring };
    var go2 = function (scope) {
      if (!scope) return;
      M4W.post('api/calendar/event/delete', { calendar_id: F.original, uri: F.uri, scope: scope, occurrence: F.occurrence }).done(function (r) {
        bootstrap.Modal.getInstance($modal[0]).hide();
        M4W.toast(r.sent ? t('cal_deleted_sent', { n: r.sent }) : t('cal_deleted'), 'success');
        load();
      });
    };
    if (F.recurring) scopeFor(e, t('cal_delete_which')).then(go2);
    else M4W.confirm(t('cal_delete_title'), F.attendees.length && F.organizer ? t('cal_delete_notify') : t('cal_delete_text'), t('delete')).then(function () { go2('all'); });
  });

  $f.on('click', '[data-partstat]', function () {
    var ps = $(this).data('partstat');
    M4W.post('api/calendar/event/partstat', { calendar_id: F.original, uri: F.uri, partstat: ps }).done(function () {
      bootstrap.Modal.getInstance($modal[0]).hide(); M4W.toast(t('cal_answered'), 'success'); load();
    });
  });

  // ---------------------------------------------------------------- calendars: create, share, delete
  var $cm = $('#cal-calendar'), $cf = $('[data-cal-calform]'), C = null;
  function openCalendar(c, kind) {
    C = c ? $.extend(true, {}, c) : { id: 0, name: '', color: BOOT.colors[Math.floor(Math.random() * BOOT.colors.length)], kind: kind || 'personal', acl: [] };
    $cf[0].reset();
    $('#cal-calendar-title').text(c ? (c.kind === 'room' ? t('cal_edit_room') : t('cal_edit_share')) : (C.kind === 'room' ? t('cal_new_room') : t('cal_new_calendar')));
    $cf.find('[name=name]').val(C.name);
    var $col = $cf.find('[data-cal-colors]').empty();
    BOOT.colors.forEach(function (col, i) {
      var id = 'cal-col-' + i;
      $col.append($('<input type="radio" class="btn-check" name="color">').attr('id', id).val(col).prop('checked', col === C.color));
      $col.append($('<label class="m4w-cal-swatch"></label>').attr('for', id).css('--ev', col).attr('title', col).append($('<span class="visually-hidden"></span>').text(col)));
    });
    $cf.find('[data-cal-share]').toggle(C.kind !== 'room');
    var $p = $cf.find('[data-cal-acl-person]').empty().append($('<option value="0"></option>').text(t('cal_everyone')));
    BOOT.people.forEach(function (p, i) { $p.append($('<option></option>').val(i + 1).text(p.name + ' <' + p.email + '>')); });
    renderAcl();
    $cf.find('[data-cal-c-delete]').toggleClass('d-none', !c || (c.kind === 'personal' && S.calendars.filter(function (x) { return x.mine && x.kind === 'personal'; })[0] === c));
    bootstrap.Modal.getOrCreateInstance($cm[0]).show();
  }
  function renderAcl() {
    var $a = $cf.find('[data-cal-acl]').empty();
    if (!C.acl.length) $a.append($('<div class="small text-muted"></div>').text(t('cal_not_shared')));
    C.acl.forEach(function (a, i) {
      var $r = $('<div class="d-flex align-items-center gap-2 py-1"></div>');
      $r.append($('<span class="flex-grow-1 text-truncate"></span>').text(+a.user_id === 0 ? t('cal_everyone') : (a.display_name || a.email)));
      $r.append($('<span class="badge badge-soft-primary"></span>').text(a.level === 'write' ? t('cal_level_write') : t('cal_level_read')));
      $r.append($('<button type="button" class="btn btn-ghost btn-icon btn-sm"><i class="bi bi-x-lg"></i></button>').attr('data-acl-rm', i).attr('aria-label', t('cal_unshare', { name: a.display_name || a.email || t('cal_everyone') })));
      $a.append($r);
    });
  }
  $cf.on('click', '[data-cal-acl-add]', function () {
    var idx = +$cf.find('[data-cal-acl-person]').val(), level = $cf.find('[data-cal-acl-level]').val();
    var who = idx === 0 ? { user_id: 0, email: '', display_name: '' } : BOOT.people[idx - 1];
    if (!who) return;
    var uid = idx === 0 ? 0 : who.id;
    C.acl = C.acl.filter(function (a) { return idx === 0 ? +a.user_id !== 0 : (a.email || '').toLowerCase() !== who.email.toLowerCase(); });
    C.acl.push({ user_id: uid, email: who.email, display_name: who.name || '', level: level });
    renderAcl();
  });
  $cf.on('click', '[data-acl-rm]', function () { C.acl.splice(+$(this).data('acl-rm'), 1); renderAcl(); });
  $cf.on('submit', function (ev) {
    ev.preventDefault();
    var name = $cf.find('[name=name]').val().trim();
    if (!name) { $cf.find('[name=name]').addClass('is-invalid').trigger('focus'); return; }
    var color = $cf.find('[name=color]:checked').val() || C.color;
    var acl = C.acl.map(function (a) { return { user_id: +a.user_id, email: a.email, level: a.level }; });
    var done = function (r) { S.calendars = r.calendars || S.calendars; bootstrap.Modal.getInstance($cm[0]).hide(); renderLists(); load(); M4W.toast(t('cal_calendar_saved'), 'success'); };
    if (C.id) M4W.post('api/calendar/calendars/' + C.id, { name: name, color: color, acl: C.kind === 'room' ? undefined : aclIds(acl) }).done(done);
    else M4W.post('api/calendar/calendars', { name: name, color: color, kind: C.kind }).done(function (r) {
      if (acl.length && C.kind !== 'room') M4W.post('api/calendar/calendars/' + r.id, { acl: aclIds(acl) }).done(done); else done(r);
    });
  });
  function aclIds(acl) {
    return acl.map(function (a) {
      if (+a.user_id > 0 || a.email === '') return { user_id: +a.user_id, level: a.level };
      var p = BOOT.people.filter(function (x) { return x.email.toLowerCase() === (a.email || '').toLowerCase(); })[0];
      return p ? { user_id: p.id, level: a.level } : null;
    }).filter(Boolean);
  }
  $cf.on('click', '[data-cal-c-delete]', function () {
    M4W.confirm(t('cal_c_delete_title'), t('cal_c_delete_text', { name: C.name }), t('delete')).then(function () {
      M4W.post('api/calendar/calendars/' + C.id + '/delete', {}).done(function (r) { S.calendars = r.calendars; bootstrap.Modal.getInstance($cm[0]).hide(); renderLists(); load(); });
    });
  });
  $(document).on('click', '[data-cal-add]', function () { openCalendar(null, 'personal'); });
  $(document).on('click', '[data-cal-add-room]', function () { openCalendar(null, 'room'); });
  $(document).on('click', '[data-cal-edit]', function () { openCalendar(calById($(this).data('cal-edit'))); });

  // Import an .ics file
  var importInto = 0;
  $(document).on('click', '[data-cal-import]', function () { importInto = +$(this).data('cal-import'); $('[data-cal-importfile]').val('').trigger('click'); });
  $('[data-cal-importfile]').on('change', function () {
    if (!this.files.length) return;
    var fd = new FormData(); fd.append('file', this.files[0]); fd.append('calendar_id', importInto);
    M4W.post('api/calendar/import', fd).done(function (r) { M4W.toast(t('cal_imported', { n: r.count }), 'success'); load(); });
  });

  renderLists();
  load();
  // Keep the "now" line and other people's changes fresh.
  setInterval(function () { if (!$('.modal.show').length && !drag && document.visibilityState === 'visible') load(); }, 120000);
})(jQuery, window, document);
