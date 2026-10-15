/* Make4WebMail — built-in spreadsheet (several editors at once).
 * The server keeps the workbook and recalculates it; this page sends small operations
 * and applies the patches every editor receives by polling. */
(function ($) {
  'use strict';
  var $root = $('[data-sheet]');
  if (!$root.length) return;
  var t = M4W.t, esc = M4W.esc;
  var ID = $root.data('id');
  var API = 'api/sheets/' + ID;
  var HEAD_H = 24, HEAD_W = 46, ROW_H = 21, BUF = 4;

  var $grid = $root.find('[data-grid]'), $scroll = $root.find('[data-scroll]'), $inner = $root.find('[data-inner]');
  var $cells = $root.find('[data-cells]'), $colhead = $root.find('[data-colhead]'), $rowhead = $root.find('[data-rowhead]');
  var $sel = $root.find('[data-sel]'), $editor = $root.find('[data-editor]'), $others = $root.find('[data-others]');
  var $fx = $root.find('[data-fx]'), $addr = $root.find('[data-addr]'), $status = $root.find('[data-status]');
  var $tabs = $root.find('[data-tabs]'), $ctx = $root.find('[data-ctx]'), $sumBox = $root.find('[data-sum]');

  var book = null, styles = {}, cur = 0, seq = 0, mode = 'view', canWrite = false;
  var geo = { colX: [0], rowY: [0], nCols: 0, nRows: 0 };
  var sel = { r1: 1, c1: 1, r2: 1, c2: 1 }, act = { r: 1, c: 1 };
  var edit = null; // {r, c, typed, old}
  var queue = [], sending = false, flushTimer = null, pollTimer = null, people = [], dirty = false, lastPub = 0;
  var undo = [], redo = [];

  // ------------------------------------------------------------ addresses
  function colName(c) { var s = ''; while (c > 0) { var m = (c - 1) % 26; s = String.fromCharCode(65 + m) + s; c = Math.floor((c - 1) / 26); } return s; }
  function colIndex(s) { var n = 0; for (var i = 0; i < s.length; i++) n = n * 26 + (s.charCodeAt(i) - 64); return n; }
  function addr(r, c) { return colName(c) + r; }
  function parseAddr(a) { var m = /^([A-Z]{1,3})(\d+)$/.exec(String(a).toUpperCase()); return m ? { c: colIndex(m[1]), r: +m[2] } : null; }
  function rangeStr(s) { var a = addr(Math.min(s.r1, s.r2), Math.min(s.c1, s.c2)), b = addr(Math.max(s.r1, s.r2), Math.max(s.c1, s.c2)); return a === b ? a : a + ':' + b; }
  function norm(s) { return { r1: Math.min(s.r1, s.r2), c1: Math.min(s.c1, s.c2), r2: Math.max(s.r1, s.r2), c2: Math.max(s.c1, s.c2) }; }

  function sh() { return book.sheets[cur]; }
  function cell(r, c) { return sh().cells[addr(r, c)] || null; }
  function editText(r, c) { var v = cell(r, c); return v ? v[1] : ''; }
  function styleOf(v) { return v ? (styles[v[2]] || {}) : {}; }

  // ------------------------------------------------------------ geometry
  function colW(c) { var w = sh().colw[c]; return w === undefined ? sh().defw : w; }
  function rowH(r) { var h = sh().rowh[r]; return h === undefined ? ROW_H : h; }
  function buildGeo() {
    var s = sh();
    geo.nCols = Math.max(s.cols + 10, 30);
    geo.nRows = Math.max(s.rows + 100, 200);
    geo.colX = [0];
    for (var c = 1; c <= geo.nCols; c++) geo.colX.push(geo.colX[c - 1] + colW(c));
    geo.rowY = [0];
    for (var r = 1; r <= geo.nRows; r++) geo.rowY.push(geo.rowY[r - 1] + rowH(r));
    $inner.css({ width: geo.colX[geo.nCols] + 'px', height: geo.rowY[geo.nRows] + 'px' });
  }
  function grow(r, c) {
    var s = sh(), changed = false;
    if (r > geo.nRows - 20) { s.rows = Math.max(s.rows, r); changed = true; }
    if (c > geo.nCols - 3) { s.cols = Math.max(s.cols, c); changed = true; }
    if (changed) buildGeo();
  }
  function find(arr, px) { var lo = 0, hi = arr.length - 1; while (lo < hi) { var m = (lo + hi + 1) >> 1; if (arr[m] <= px) lo = m; else hi = m - 1; } return lo + 1; }
  function rect(r1, c1, r2, c2) { return { x: geo.colX[c1 - 1], y: geo.rowY[r1 - 1], w: geo.colX[c2] - geo.colX[c1 - 1], h: geo.rowY[r2] - geo.rowY[r1 - 1] }; }
  function mergeAt(r, c) {
    var ms = sh().merges;
    for (var i = 0; i < ms.length; i++) {
      var p = ms[i].split(':'), a = parseAddr(p[0]), b = parseAddr(p[1] || p[0]);
      if (a && b && r >= a.r && r <= b.r && c >= a.c && c <= b.c) return { r1: a.r, c1: a.c, r2: b.r, c2: b.c };
    }
    return null;
  }

  // ------------------------------------------------------------ rendering
  var rafPending = false;
  function render() {
    if (rafPending) return;
    rafPending = true;
    requestAnimationFrame(function () { rafPending = false; draw(); });
  }
  function cssFor(st, num, err) {
    var css = [];
    if (st.b) css.push('font-weight:700');
    if (st.i) css.push('font-style:italic');
    var deco = [st.u ? 'underline' : '', st.s ? 'line-through' : ''].filter(Boolean).join(' ');
    if (deco) css.push('text-decoration:' + deco);
    if (st.fc) css.push('color:' + st.fc);
    if (err) css.push('color:#dc2626');
    if (st.bg) css.push('background:' + st.bg);
    if (st.fs) css.push('font-size:' + Math.round(st.fs * 4 / 3) + 'px');
    var ha = st.ha || (num ? 'right' : '');
    if (ha) css.push('justify-content:' + ({ left: 'flex-start', center: 'center', right: 'flex-end', justify: 'flex-start' }[ha]) + ';text-align:' + ha);
    if (st.va) css.push('align-items:' + (st.va === 'top' ? 'flex-start' : 'center'));
    if (st.wrap) css.push('white-space:pre-wrap;word-break:break-word');
    if (st.bd) {
      if (st.bd.t) css.push('border-top:1px solid ' + st.bd.t);
      if (st.bd.r) css.push('border-right:1px solid ' + st.bd.r);
      if (st.bd.bo) css.push('border-bottom:1px solid ' + st.bd.bo);
      if (st.bd.l) css.push('border-left:1px solid ' + st.bd.l);
    }
    return css.join(';');
  }
  function draw() {
    if (!book) return;
    var el = $scroll[0], top = el.scrollTop, left = el.scrollLeft, H = el.clientHeight, W = el.clientWidth;
    var r1 = Math.max(1, find(geo.rowY, top) - BUF), r2 = Math.min(geo.nRows, find(geo.rowY, top + H) + BUF);
    var c1 = Math.max(1, find(geo.colX, left) - 1), c2 = Math.min(geo.nCols, find(geo.colX, left + W) + 1);
    if (r2 >= geo.nRows - 5 || c2 >= geo.nCols - 2) { grow(r2 + 50, c2 + 5); }
    var html = [], done = {};
    var ms = sh().merges.map(function (m) { var p = m.split(':'), a = parseAddr(p[0]), b = parseAddr(p[1] || p[0]); return { r1: a.r, c1: a.c, r2: b.r, c2: b.c }; });
    // Merged areas first (they may start outside the window).
    ms.forEach(function (m) {
      if (m.r2 < r1 || m.r1 > r2 || m.c2 < c1 || m.c1 > c2) return;
      for (var r = m.r1; r <= m.r2; r++) for (var c = m.c1; c <= m.c2; c++) done[r + ':' + c] = 1;
      html.push(cellHtml(m.r1, m.c1, rect(m.r1, m.c1, m.r2, m.c2), true));
    });
    for (var r = r1; r <= r2; r++) {
      for (var c = c1; c <= c2; c++) {
        if (done[r + ':' + c]) continue;
        html.push(cellHtml(r, c, rect(r, c, r, c), false));
      }
    }
    $cells[0].innerHTML = html.join('');
    // Headers
    var ch = [];
    var s0 = norm(sel);
    for (var cc = c1; cc <= c2; cc++) {
      ch.push('<div class="h' + (cc >= s0.c1 && cc <= s0.c2 ? ' on' : '') + '" data-col="' + cc + '" style="left:' + geo.colX[cc - 1] + 'px;width:' + colW(cc) + 'px">' + colName(cc) + '<span class="rz" data-rz-col="' + cc + '"></span></div>');
    }
    $colhead.html('<div class="in" style="transform:translateX(' + (-left) + 'px);width:' + geo.colX[geo.nCols] + 'px">' + ch.join('') + '</div>');
    var rh = [];
    for (var rr = r1; rr <= r2; rr++) {
      rh.push('<div class="h' + (rr >= s0.r1 && rr <= s0.r2 ? ' on' : '') + '" data-row="' + rr + '" style="top:' + geo.rowY[rr - 1] + 'px;height:' + rowH(rr) + 'px">' + rr + '<span class="rz" data-rz-row="' + rr + '"></span></div>');
    }
    $rowhead.html('<div class="in" style="transform:translateY(' + (-top) + 'px)">' + rh.join('') + '</div>');
    drawSel();
    drawOthers();
  }
  // A number never shows cut: like a spreadsheet, "###" when the column is too narrow.
  var measureCtx = document.createElement('canvas').getContext('2d'), widths = {};
  function textWidth(text, st) {
    var font = (st.b ? '700 ' : '400 ') + (st.fs ? Math.round(st.fs * 4 / 3) : 13) + 'px ' + getComputedStyle(document.body).fontFamily;
    var k = font + '|' + text;
    if (widths[k] === undefined) { measureCtx.font = font; widths[k] = measureCtx.measureText(text).width; }
    return widths[k];
  }
  function cellHtml(r, c, rc, merged) {
    var v = cell(r, c), st = styleOf(v), text = v ? v[0] : '';
    var num = v && v[3] === 1, err = /^#[A-Z0-9/!?]+[!?]?$/.test(text) && text.length < 12;
    var title = '';
    if (num && text && !st.wrap && textWidth(text, st) > rc.w - 9) {
      title = text;
      text = new Array(Math.max(1, Math.floor((rc.w - 9) / Math.max(1, textWidth('#', st)))) + 1).join('#');
    }
    // Text spills over empty neighbours, like in a spreadsheet, and stops at the first one
    // that holds something (or a merged area).
    var spill = text && !num && !st.wrap && !merged && (!st.ha || st.ha === 'left') && !cell(r, c + 1) && !mergeAt(r, c + 1);
    var w = rc.w;
    if (spill) {
      var need = textWidth(text, st) + 9;
      for (var cc = c + 1; w < need && cc <= geo.nCols && cc <= c + 50 && !cell(r, cc) && !mergeAt(r, cc); cc++) w += colW(cc);
    }
    var css = 'left:' + rc.x + 'px;top:' + rc.y + 'px;width:' + w + 'px;height:' + rc.h + 'px;' + cssFor(st, num, err);
    return '<div class="c' + (merged ? ' m' : '') + (spill ? ' sp' : '') + '" style="' + css + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + esc(text) + '</div>';
  }
  function drawSel() {
    var s = norm(sel);
    var m = mergeAt(act.r, act.c);
    if (s.r1 === s.r2 && s.c1 === s.c2 && m) s = m;
    var rc = rect(s.r1, s.c1, s.r2, s.c2);
    $sel.css({ left: rc.x - 1, top: rc.y - 1, width: rc.w + 1, height: rc.h + 1 }).toggleClass('multi', !(s.r1 === s.r2 && s.c1 === s.c2));
    $addr.val(rangeStr(sel) === addr(act.r, act.c) ? addr(act.r, act.c) : rangeStr(sel));
    if (!edit) $fx.val(editText(act.r, act.c));
    toolbarState();
    sumSelection();
  }
  function drawOthers() {
    var html = people.filter(function (p) { return p.sheet === cur && p.cell; }).map(function (p) {
      var a = parseAddr(p.cell); if (!a || a.r > geo.nRows || a.c > geo.nCols) return '';
      var rc = rect(a.r, a.c, a.r, a.c);
      return '<div class="o" style="left:' + (rc.x - 1) + 'px;top:' + (rc.y - 1) + 'px;width:' + (rc.w + 1) + 'px;height:' + (rc.h + 1) + 'px;border-color:' + p.color + '"><span style="background:' + p.color + '">' + esc(p.name) + '</span></div>';
    });
    $others.html(html.join(''));
  }
  function toolbarState() {
    var st = styleOf(cell(act.r, act.c));
    ['b', 'i', 'u', 's'].forEach(function (k) { $root.find('[data-cmd="' + k + '"]').toggleClass('active', !!st[k]); });
    ['left', 'center', 'right'].forEach(function (k) { $root.find('[data-cmd="' + k + '"]').toggleClass('active', st.ha === k); });
    $root.find('[data-cmd="wrap"]').toggleClass('active', !!st.wrap);
    $root.find('[data-nf]').val(st.nf || 'general');
  }
  function sumSelection() {
    var s = norm(sel), n = 0, sum = 0, count = 0;
    if (s.r1 === s.r2 && s.c1 === s.c2) { $sumBox.text(''); return; }
    var cells = sh().cells;
    Object.keys(cells).forEach(function (a) {
      var p = parseAddr(a); if (!p || p.r < s.r1 || p.r > s.r2 || p.c < s.c1 || p.c > s.c2) return;
      var v = cells[a]; if (v[0] !== '') count++;
      if (v[3] === 1) { var x = parseFloat(String(v[1]).replace(/\s| | /g, '').replace(',', '.').replace('%', '')); if (!isNaN(x) && v[1].charAt(0) !== '=') { sum += x; n++; } }
    });
    $sumBox.text(n ? t('sheet_sum', { sum: (Math.round(sum * 1e6) / 1e6).toLocaleString(), n: count }) : (count ? t('sheet_count', { n: count }) : ''));
  }
  function tabsHtml() {
    $tabs.html(book.sheets.map(function (s, i) {
      return '<button type="button" role="tab" class="tab' + (i === cur ? ' on' : '') + '" aria-selected="' + (i === cur) + '" data-tab="' + i + '">' + esc(s.name) + '</button>';
    }).join(''));
  }
  function scrollIntoView(r, c) {
    var el = $scroll[0], rc = rect(r, c, r, c);
    if (rc.y < el.scrollTop) el.scrollTop = rc.y;
    else if (rc.y + rc.h > el.scrollTop + el.clientHeight) el.scrollTop = rc.y + rc.h - el.clientHeight;
    if (rc.x < el.scrollLeft) el.scrollLeft = rc.x;
    else if (rc.x + rc.w > el.scrollLeft + el.clientWidth) el.scrollLeft = rc.x + rc.w - el.clientWidth;
  }

  // ------------------------------------------------------------ status, people
  function setStatus() {
    var s;
    if (mode === 'view') s = t('sheet_read_only');
    else if (sending || queue.length) s = t('sheet_sending');
    else if (dirty) s = t('sheet_kept');
    else s = lastPub ? t('sheet_saved_at', { time: new Date(lastPub * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : t('sheet_up_to_date');
    $status.text(s);
    $root.find('[data-cmd="save"]').prop('disabled', mode === 'view' || !dirty || sending || queue.length > 0);
  }
  function drawPeople() {
    $root.find('[data-people]').html(people.map(function (p) {
      return '<span class="p" style="background:' + p.color + '" title="' + esc(p.name) + '">' + esc(M4W.initials(p.name)) + '</span>';
    }).join(''));
    drawOthers();
  }

  // ------------------------------------------------------------ server
  function load(keep) {
    return M4W.get(API, null, { silent: true }).done(function (r) {
      book = r.workbook; styles = book.styles || {}; seq = r.seq; mode = r.mode; canWrite = r.can_write;
      if (!keep) cur = Math.min(book.active || 0, book.sheets.length - 1);
      cur = Math.min(cur, book.sheets.length - 1);
      dirty = r.dirty; people = r.people || [];
      $root.toggleClass('is-readonly', !canWrite);
      var notice = r.reason && r.reason !== 'rights' || !canWrite ? t('sheet_reason_' + (r.reason || 'rights')) : '';
      $root.find('[data-notice]').toggleClass('d-none', !notice).text(notice);
      $root.find('[data-loading]').addClass('d-none');
      tabsHtml(); buildGeo(); draw(); drawPeople(); setStatus();
      if (!pollTimer) poll();
    }).fail(function (x) {
      $root.find('[data-loading]').html('<div class="text-center p-4"><i class="bi bi-exclamation-triangle fs-2 text-warning"></i><p class="mt-2">' + esc((x.responseJSON && x.responseJSON.error) || t('error_network')) + '</p></div>');
    });
  }
  function send(op) {
    if (!canWrite) return;
    queue.push(op);
    clearTimeout(flushTimer);
    flushTimer = setTimeout(flush, 120);
    setStatus();
  }
  function flush() {
    if (sending || !queue.length) return;
    sending = true;
    var ops = queue.splice(0, 500);
    M4W.post(API + '/ops', { ops: ops }, { silent: true }).done(function () { dirty = true; })
      .fail(function () { load(true); })
      .always(function () { sending = false; setStatus(); if (queue.length) flush(); else { clearTimeout(pollTimer); poll(); } });
  }
  function poll() {
    clearTimeout(pollTimer);
    if (!book) return;
    M4W.get(API + '/poll', { since: seq, sheet: cur, cell: addr(act.r, act.c) }, { silent: true, quiet: true }).done(function (r) {
      var reload = false, touched = false;
      (r.ops || []).forEach(function (op) {
        seq = Math.max(seq, op.seq);
        if (op.k === 'reload') reload = true;
        else if (op.k === 'patch') {
          var s = book.sheets[op.s]; if (!s) { reload = true; return; }
          $.extend(styles, op.styles || {});
          Object.keys(op.cells).forEach(function (a) {
            var v = op.cells[a];
            if (v === null) delete s.cells[a]; else s.cells[a] = v;
            var p = parseAddr(a); if (p) { s.rows = Math.max(s.rows, p.r); s.cols = Math.max(s.cols, p.c); }
          });
          if (op.s === cur) touched = true;
        } else if (op.k === 'published') {
          lastPub = Math.floor(Date.now() / 1000);
          if (op.copy) M4W.toast(t('sheet_saved_copy', { name: op.copy }), 'success');
        }
      });
      people = r.people || []; dirty = r.dirty; if (r.published_at) lastPub = r.published_at;
      if (r.gone) { load(true); return; }
      if (reload) load(true); else if (touched) render();
      drawPeople(); setStatus();
    }).always(function () {
      pollTimer = setTimeout(poll, document.hidden ? 10000 : (people.length || queue.length || sending ? 1000 : 2500));
    });
  }

  // ------------------------------------------------------------ editing
  function setLocal(r, c, text) {
    var a = addr(r, c), s = sh(), v = s.cells[a];
    if (text === '') { if (v && v[2]) s.cells[a] = ['', '', v[2], 0]; else delete s.cells[a]; }
    else s.cells[a] = [text.charAt(0) === '=' ? '…' : text, text, v ? v[2] : 0, /^-?[\d\s .,]+%?$/.test(text) ? 1 : 0];
  }
  function record(sheet, before, after) { undo.push({ s: sheet, before: before, after: after }); if (undo.length > 200) undo.shift(); redo = []; }
  function commitValue(r, c, text) {
    var old = editText(r, c);
    if (text === old) return;
    var b = {}, a = {}; b[addr(r, c)] = old; a[addr(r, c)] = text;
    record(cur, b, a);
    setLocal(r, c, text);
    send({ t: 'set', s: cur, c: addr(r, c), v: text });
    grow(r, c);
    render();
  }
  function startEdit(typed, initial) {
    if (!canWrite) return;
    var m = mergeAt(act.r, act.c) || { r1: act.r, c1: act.c, r2: act.r, c2: act.c };
    var rc = rect(m.r1, m.c1, m.r2, m.c2);
    edit = { r: act.r, c: act.c, typed: typed };
    var v = initial !== undefined ? initial : editText(act.r, act.c);
    $editor.removeClass('d-none').css({ left: rc.x, top: rc.y, minWidth: rc.w, height: Math.max(rc.h, ROW_H) }).val(v);
    var st = styleOf(cell(act.r, act.c));
    $editor.css({ fontWeight: st.b ? 700 : 400, fontStyle: st.i ? 'italic' : 'normal' });
    $editor[0].focus();
    var len = $editor.val().length; $editor[0].setSelectionRange(len, len);
    $fx.val(v);
  }
  function endEdit(save, move) {
    if (!edit) return;
    var e = edit; edit = null;
    var val = $editor.val();
    $editor.addClass('d-none');
    if (save) commitValue(e.r, e.c, val);
    $scroll[0].focus({ preventScroll: true });
    if (move) moveBy(move[0], move[1], false);
    else drawSel();
  }
  function moveBy(dr, dc, extend) {
    var m = mergeAt(act.r, act.c);
    if (m && !extend) { if (dr > 0) act.r = m.r2; if (dc > 0) act.c = m.c2; }
    var r = Math.max(1, (extend ? sel.r2 : act.r) + dr), c = Math.max(1, (extend ? sel.c2 : act.c) + dc);
    grow(r, c);
    if (extend) { sel.r2 = r; sel.c2 = c; }
    else { act = { r: r, c: c }; sel = { r1: r, c1: c, r2: r, c2: c }; }
    scrollIntoView(r, c);
    render();
  }
  function selectCell(r, c, extend) {
    if (extend) { sel.r2 = r; sel.c2 = c; }
    else { act = { r: r, c: c }; sel = { r1: r, c1: c, r2: r, c2: c }; }
    render();
  }
  function clearSelection() {
    var s = norm(sel), before = {}, after = {}, cells = sh().cells;
    Object.keys(cells).forEach(function (a) {
      var p = parseAddr(a); if (!p || p.r < s.r1 || p.r > s.r2 || p.c < s.c1 || p.c > s.c2) return;
      if (cells[a][1] !== '') { before[a] = cells[a][1]; after[a] = ''; setLocal(p.r, p.c, ''); }
    });
    if (!Object.keys(before).length) return;
    record(cur, before, after);
    send({ t: 'clear', s: cur, r: rangeStr(sel) });
    render();
  }
  function applyChanges(sheet, map) {
    if (sheet !== cur) { cur = sheet; tabsHtml(); buildGeo(); }
    Object.keys(map).forEach(function (a) {
      var p = parseAddr(a); setLocal(p.r, p.c, map[a]);
      send({ t: 'set', s: sheet, c: a, v: map[a] });
    });
    render();
  }
  function doUndo() { var u = undo.pop(); if (!u) return; redo.push(u); applyChanges(u.s, u.before); }
  function doRedo() { var u = redo.pop(); if (!u) return; undo.push(u); applyChanges(u.s, u.after); }

  function style(k, v) { send({ t: 'style', s: cur, r: rangeStr(sel), k: k, v: v }); }
  function toggle(k) { style(k, !styleOf(cell(act.r, act.c))[k]); }

  // ------------------------------------------------------------ clipboard
  function selectionTsv() {
    var s = norm(sel), rows = [];
    for (var r = s.r1; r <= s.r2; r++) {
      var row = [];
      for (var c = s.c1; c <= s.c2; c++) { var v = cell(r, c), x = v ? v[0] : ''; row.push(/[\t\n"]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x); }
      rows.push(row.join('\t'));
    }
    return rows.join('\r\n');
  }
  function parseTsv(text) {
    var rows = [], row = [], f = '', q = false;
    text = text.replace(/\r\n?/g, '\n');
    for (var i = 0; i < text.length; i++) {
      var ch = text[i];
      if (q) { if (ch === '"') { if (text[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += ch; }
      else if (ch === '"' && f === '') q = true;
      else if (ch === '\t') { row.push(f); f = ''; }
      else if (ch === '\n') { row.push(f); rows.push(row); row = []; f = ''; }
      else f += ch;
    }
    if (f !== '' || row.length) { row.push(f); rows.push(row); }
    return rows;
  }
  document.addEventListener('copy', function (e) {
    if (edit || !$scroll.is(':focus')) return;
    e.clipboardData.setData('text/plain', selectionTsv()); e.preventDefault();
  });
  document.addEventListener('cut', function (e) {
    if (edit || !$scroll.is(':focus')) return;
    e.clipboardData.setData('text/plain', selectionTsv()); e.preventDefault();
    if (canWrite) clearSelection();
  });
  document.addEventListener('paste', function (e) {
    if (edit || !$scroll.is(':focus') || !canWrite) return;
    var rows = parseTsv(e.clipboardData.getData('text/plain') || '');
    if (!rows.length) return;
    e.preventDefault();
    var s = norm(sel), before = {}, after = {};
    rows.forEach(function (row, dr) { row.forEach(function (v, dc) { var a = addr(s.r1 + dr, s.c1 + dc); before[a] = editText(s.r1 + dr, s.c1 + dc); after[a] = v; setLocal(s.r1 + dr, s.c1 + dc, v); }); });
    record(cur, before, after);
    send({ t: 'setmany', s: cur, at: addr(s.r1, s.c1), rows: rows });
    sel = { r1: s.r1, c1: s.c1, r2: s.r1 + rows.length - 1, c2: s.c1 + Math.max.apply(null, rows.map(function (r) { return r.length; })) - 1 };
    grow(sel.r2, sel.c2);
    render();
  });

  // ------------------------------------------------------------ mouse
  function cellAt(ev) {
    var o = $inner[0].getBoundingClientRect();
    return { r: Math.min(geo.nRows, find(geo.rowY, ev.clientY - o.top)), c: Math.min(geo.nCols, find(geo.colX, ev.clientX - o.left)) };
  }
  var dragging = false;
  $inner.on('mousedown', function (e) {
    if (e.button !== 0 || $(e.target).is($editor)) return;
    if (edit) endEdit(true);
    var p = cellAt(e);
    selectCell(p.r, p.c, e.shiftKey);
    dragging = true;
    $scroll[0].focus({ preventScroll: true });
    e.preventDefault();
  });
  $(document).on('mousemove', function (e) {
    if (!dragging) return;
    var p = cellAt(e);
    if (p.r !== sel.r2 || p.c !== sel.c2) { sel.r2 = p.r; sel.c2 = p.c; render(); }
  }).on('mouseup', function () { dragging = false; });
  $inner.on('dblclick', function (e) { if (!$(e.target).is($editor)) startEdit(false); });
  $scroll.on('scroll', render);
  $(window).on('resize', render);

  // Header click: whole row / column; drag the edge: size.
  $colhead.on('mousedown', '[data-col]', function (e) {
    if ($(e.target).is('[data-rz-col]')) return;
    var c = +$(this).data('col');
    if (e.shiftKey) { sel.c2 = c; sel.r1 = 1; sel.r2 = geo.nRows; } else { act = { r: 1, c: c }; sel = { r1: 1, c1: c, r2: Math.max(sh().rows, 1), c2: c }; }
    render(); $scroll[0].focus({ preventScroll: true });
  });
  $rowhead.on('mousedown', '[data-row]', function (e) {
    if ($(e.target).is('[data-rz-row]')) return;
    var r = +$(this).data('row');
    if (e.shiftKey) { sel.r2 = r; } else { act = { r: r, c: 1 }; sel = { r1: r, c1: 1, r2: r, c2: Math.max(sh().cols, 1) }; }
    render(); $scroll[0].focus({ preventScroll: true });
  });
  function resizer(e, isCol, idx) {
    if (!canWrite) return;
    e.preventDefault(); e.stopPropagation();
    var start = isCol ? e.clientX : e.clientY, orig = isCol ? colW(idx) : rowH(idx);
    var $guide = $('<div class="m4w-sheet-guide' + (isCol ? ' v' : ' h') + '"></div>').appendTo($inner);
    var pos = function (size) { if (isCol) $guide.css({ left: geo.colX[idx - 1] + size }); else $guide.css({ top: geo.rowY[idx - 1] + size }); };
    var size = orig; pos(size);
    $(document).on('mousemove.rz', function (ev) { size = Math.max(isCol ? 20 : 12, orig + (isCol ? ev.clientX : ev.clientY) - start); pos(size); })
      .on('mouseup.rz', function () {
        $(document).off('.rz'); $guide.remove();
        if (size === orig) return;
        if (isCol) { sh().colw[idx] = Math.round(size); send({ t: 'colw', s: cur, col: idx, w: Math.round(size) }); }
        else { sh().rowh[idx] = Math.round(size); send({ t: 'rowh', s: cur, row: idx, h: Math.round(size) }); }
        buildGeo(); render();
      });
  }
  $colhead.on('mousedown', '[data-rz-col]', function (e) { resizer(e, true, +$(this).data('rz-col')); });
  // Double-click on the edge: the column fits its content.
  $colhead.on('dblclick', '[data-rz-col]', function (e) {
    e.preventDefault(); e.stopPropagation();
    if (!canWrite) return;
    var c = +$(this).data('rz-col'), w = 0, cells = sh().cells;
    Object.keys(cells).forEach(function (a) { var p = parseAddr(a); if (p && p.c === c && !mergeAt(p.r, p.c)) w = Math.max(w, textWidth(cells[a][0], styleOf(cells[a]))); });
    if (!w) return;
    w = Math.min(600, Math.ceil(w + 12));
    sh().colw[c] = w; send({ t: 'colw', s: cur, col: c, w: w }); buildGeo(); render();
  });
  $rowhead.on('mousedown', '[data-rz-row]', function (e) { resizer(e, false, +$(this).data('rz-row')); });

  // ------------------------------------------------------------ keyboard
  $grid.on('keydown', function (e) {
    if (edit || $(e.target).is('input, select, textarea')) return;
    var k = e.key, mod = e.ctrlKey || e.metaKey;
    if (mod) {
      var l = k.toLowerCase();
      if (l === 'z') { e.preventDefault(); if (canWrite) (e.shiftKey ? doRedo : doUndo)(); return; }
      if (l === 'y') { e.preventDefault(); if (canWrite) doRedo(); return; }
      if (l === 's') { e.preventDefault(); save(); return; }
      if (l === 'b' || l === 'i' || l === 'u') { e.preventDefault(); if (canWrite) toggle(l); return; }
      if (l === 'a') { e.preventDefault(); act = { r: 1, c: 1 }; sel = { r1: 1, c1: 1, r2: Math.max(sh().rows, 1), c2: Math.max(sh().cols, 1) }; render(); return; }
      if (k === 'Home') { e.preventDefault(); selectCell(1, 1, false); scrollIntoView(1, 1); return; }
      return;
    }
    var page = Math.max(1, Math.floor($scroll[0].clientHeight / ROW_H) - 1);
    switch (k) {
      case 'ArrowUp': e.preventDefault(); moveBy(-1, 0, e.shiftKey); return;
      case 'ArrowDown': e.preventDefault(); moveBy(1, 0, e.shiftKey); return;
      case 'ArrowLeft': e.preventDefault(); moveBy(0, -1, e.shiftKey); return;
      case 'ArrowRight': e.preventDefault(); moveBy(0, 1, e.shiftKey); return;
      case 'PageDown': e.preventDefault(); moveBy(page, 0, e.shiftKey); return;
      case 'PageUp': e.preventDefault(); moveBy(-page, 0, e.shiftKey); return;
      case 'Home': e.preventDefault(); selectCell(act.r, 1, e.shiftKey); scrollIntoView(act.r, 1); return;
      case 'Enter': e.preventDefault(); moveBy(e.shiftKey ? -1 : 1, 0, false); return;
      case 'Tab': e.preventDefault(); moveBy(0, e.shiftKey ? -1 : 1, false); return;
      case 'F2': e.preventDefault(); startEdit(false); return;
      case 'Delete': case 'Backspace': e.preventDefault(); if (canWrite) clearSelection(); return;
      case 'Escape': $ctx.removeClass('show'); return;
    }
    if (k.length === 1 && !e.altKey && canWrite) startEdit(true, '');
  });
  $editor.on('keydown', function (e) {
    var k = e.key;
    if (k === 'Enter' && !e.altKey) { e.preventDefault(); endEdit(true, [e.shiftKey ? -1 : 1, 0]); return; }
    if (k === 'Enter' && e.altKey) { e.preventDefault(); var el = this, p = el.selectionStart; el.value = el.value.slice(0, p) + '\n' + el.value.slice(el.selectionEnd); el.setSelectionRange(p + 1, p + 1); return; }
    if (k === 'Tab') { e.preventDefault(); endEdit(true, [0, e.shiftKey ? -1 : 1]); return; }
    if (k === 'Escape') { e.preventDefault(); endEdit(false); return; }
    if (edit && edit.typed && $editor.val().charAt(0) !== '=' && /^Arrow(Up|Down|Left|Right)$/.test(k)) {
      e.preventDefault();
      endEdit(true, { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[k]);
    }
  }).on('input', function () { $fx.val(this.value); this.style.width = Math.max(parseInt(this.style.minWidth, 10) || 0, this.scrollWidth) + 'px'; })
    .on('blur', function () { if (edit) setTimeout(function () { if (edit && document.activeElement !== $editor[0] && document.activeElement !== $fx[0]) endEdit(true); }, 0); });

  // Formula bar
  $fx.on('focus', function () { if (!canWrite) { this.blur(); return; } if (!edit) { edit = { r: act.r, c: act.c, typed: false, bar: true }; } })
    .on('input', function () { if (edit && !$editor.hasClass('d-none')) $editor.val(this.value); })
    .on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); var v = this.value, ed = edit; edit = null; $editor.addClass('d-none'); if (ed) commitValue(ed.r, ed.c, v); $scroll[0].focus(); moveBy(1, 0, false); }
      else if (e.key === 'Escape') { e.preventDefault(); edit = null; $editor.addClass('d-none'); $fx.val(editText(act.r, act.c)); $scroll[0].focus(); }
    }).on('blur', function () { if (edit && edit.bar) { var v = this.value, ed = edit; edit = null; commitValue(ed.r, ed.c, v); } });
  $addr.on('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var p = this.value.toUpperCase().split(':'), a = parseAddr(p[0]), b = parseAddr(p[1] || p[0]);
    if (a && b) { grow(Math.max(a.r, b.r), Math.max(a.c, b.c)); act = { r: a.r, c: a.c }; sel = { r1: a.r, c1: a.c, r2: b.r, c2: b.c }; scrollIntoView(a.r, a.c); render(); $scroll[0].focus(); }
  });

  // ------------------------------------------------------------ commands
  function save() {
    if (!canWrite || !dirty) return;
    var go = function () {
      M4W.post(API + '/save').done(function (r) { dirty = false; lastPub = Math.floor(Date.now() / 1000); setStatus(); M4W.toast(r.message, 'success'); });
    };
    if (sending || queue.length) { flush(); setTimeout(save, 400); return; }
    go();
  }
  function autosum() {
    var r = act.r, c = act.c, start = r - 1;
    while (start >= 1 && cell(start, c) && cell(start, c)[3] === 1) start--;
    var fn = document.documentElement.lang === 'fr' ? 'SOMME' : 'SUM';
    if (start < r - 1) { commitValue(r, c, '=' + fn + '(' + addr(start + 1, c) + ':' + addr(r - 1, c) + ')'); return; }
    var sc = c - 1; while (sc >= 1 && cell(r, sc) && cell(r, sc)[3] === 1) sc--;
    if (sc < c - 1) commitValue(r, c, '=' + fn + '(' + addr(r, sc + 1) + ':' + addr(r, c - 1) + ')');
    else startEdit(false, '=' + fn + '()');
  }
  function command(cmd) {
    var s = norm(sel);
    if (cmd === 'save') return save();
    if (cmd === 'undo') return doUndo();
    if (cmd === 'redo') return doRedo();
    if (!canWrite) return;
    switch (cmd) {
      case 'b': case 'i': case 'u': case 's': return toggle(cmd);
      case 'left': case 'center': case 'right': return style('ha', styleOf(cell(act.r, act.c)).ha === cmd ? 'general' : cmd);
      case 'wrap': return toggle('wrap');
      case 'nofill': return style('bg', '');
      case 'border': return style('border', !styleOf(cell(act.r, act.c)).bd);
      case 'merge': { var m = mergeAt(act.r, act.c); return send(m ? { t: 'unmerge', s: cur, r: rangeStr(m) } : { t: 'merge', s: cur, r: rangeStr(sel) }); }
      case 'sum': return autosum();
      case 'clear': return clearSelection();
      case 'ins_row_above': return send({ t: 'ins_row', s: cur, at: s.r1, n: s.r2 - s.r1 + 1 });
      case 'ins_row_below': return send({ t: 'ins_row', s: cur, at: s.r2 + 1, n: s.r2 - s.r1 + 1 });
      case 'del_row': return send({ t: 'del_row', s: cur, at: s.r1, n: s.r2 - s.r1 + 1 });
      case 'ins_col_left': return send({ t: 'ins_col', s: cur, at: s.c1, n: s.c2 - s.c1 + 1 });
      case 'ins_col_right': return send({ t: 'ins_col', s: cur, at: s.c2 + 1, n: s.c2 - s.c1 + 1 });
      case 'del_col': return send({ t: 'del_col', s: cur, at: s.c1, n: s.c2 - s.c1 + 1 });
      case 'freeze': return send({ t: 'freeze', s: cur, c: sh().freeze ? '' : addr(act.r, act.c) });
      case 'sheet_add': return send({ t: 'sheet_add', name: '' });
    }
  }
  $root.on('click', '[data-cmd]', function (e) { e.preventDefault(); $ctx.removeClass('show'); command($(this).data('cmd')); if (!$(this).is('[data-cmd="save"]')) $scroll[0].focus({ preventScroll: true }); });
  $root.on('change', '[data-color]', function () { style($(this).data('color'), this.value); $scroll[0].focus(); });
  $root.on('change', '[data-nf]', function () { style('nf', this.value); $scroll[0].focus(); });

  // Sheets
  $tabs.on('click', '[data-tab]', function () {
    if (edit) endEdit(true);
    cur = +$(this).data('tab'); act = { r: 1, c: 1 }; sel = { r1: 1, c1: 1, r2: 1, c2: 1 };
    tabsHtml(); buildGeo(); $scroll.scrollTop(0).scrollLeft(0); render(); $scroll[0].focus();
  }).on('dblclick', '[data-tab]', function () {
    if (!canWrite) return;
    var i = +$(this).data('tab'), name = window.prompt(t('sheet_rename'), book.sheets[i].name);
    if (name && name !== book.sheets[i].name) send({ t: 'sheet_rename', s: i, name: name });
  }).on('contextmenu', '[data-tab]', function (e) {
    e.preventDefault();
    if (!canWrite || book.sheets.length < 2) return;
    var i = +$(this).data('tab');
    M4W.confirm(t('sheet_delete_title'), t('sheet_delete_text', { name: book.sheets[i].name }), t('delete')).then(function () { send({ t: 'sheet_del', s: i }); });
  });

  // Context menu
  $inner.on('contextmenu', function (e) {
    e.preventDefault();
    var p = cellAt(e), s = norm(sel);
    if (p.r < s.r1 || p.r > s.r2 || p.c < s.c1 || p.c > s.c2) selectCell(p.r, p.c, false);
    if (!canWrite) return;
    var items = [['ins_row_above', 'sheet_ins_row_above'], ['ins_row_below', 'sheet_ins_row_below'], ['del_row', 'sheet_del_row'], '-',
      ['ins_col_left', 'sheet_ins_col_left'], ['ins_col_right', 'sheet_ins_col_right'], ['del_col', 'sheet_del_col'], '-',
      ['clear', 'sheet_clear'], ['merge', mergeAt(act.r, act.c) ? 'sheet_unmerge' : 'sheet_merge']];
    $ctx.html(items.map(function (it) {
      return it === '-' ? '<hr class="dropdown-divider">' : '<button type="button" class="dropdown-item" data-cmd="' + it[0] + '">' + esc(t(it[1])) + '</button>';
    }).join(''));
    var o = $root[0].getBoundingClientRect();
    $ctx.css({ left: Math.min(e.clientX - o.left, o.width - 230), top: Math.min(e.clientY - o.top, o.height - 300) }).addClass('show');
  });
  $(document).on('mousedown', function (e) { if (!$(e.target).closest('[data-ctx]').length) $ctx.removeClass('show'); });

  // Leaving: the server keeps the work and saves it once everyone has left.
  window.addEventListener('pagehide', function () {
    if (queue.length) { try { fetch(M4W.url(API + '/ops'), { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': M4W.csrf() }, body: JSON.stringify({ ops: queue }) }); } catch (e) { /* page closing */ } }
    navigator.sendBeacon(M4W.url(API + '/leave'), new Blob([JSON.stringify({ _csrf: M4W.csrf() })], { type: 'application/json' }));
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  load(false);
  $scroll[0].focus({ preventScroll: true });
})(jQuery);
