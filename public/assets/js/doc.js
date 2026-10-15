/* Make4WebMail — built-in word processor. One person holds the pen, the others follow live. */
(function ($) {
  'use strict';
  var $root = $('[data-doc]');
  if (!$root.length) return;
  var t = M4W.t, esc = M4W.esc, API = 'api/docs/' + $root.data('id');
  var ed = null, rev = 0, me = 0, canWrite = false, writing = false, dirty = false, lastPub = 0;
  var pending = false, sending = false, draftTimer = null, pollTimer = null, people = [], pen = null, applying = false;
  var $status = $root.find('[data-status]'), $desk = $root.find('[data-desk]');

  function setStatus() {
    var s;
    if (!writing) s = pen && !pen.me ? t('doc_follow', { name: pen.name }) : (canWrite ? t('doc_reading') : t('sheet_read_only'));
    else if (pending || sending) s = t('sheet_sending');
    else if (dirty) s = t('sheet_kept');
    else s = lastPub ? t('sheet_saved_at', { time: new Date(lastPub * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : t('sheet_up_to_date');
    $status.text(s);
    $root.find('[data-save]').prop('disabled', !writing || !dirty || pending || sending);
    $root.find('[data-take]').toggleClass('d-none', writing || !canWrite).prop('disabled', !!(pen && !pen.me));
    $root.find('[data-release]').toggleClass('d-none', !writing);
    var $p = $root.find('[data-pen]');
    if (pen && !pen.me) $p.removeClass('d-none').css('background', pen.color).text(t('doc_writing', { name: pen.name }));
    else $p.addClass('d-none');
    $root.find('[data-people]').html(people.map(function (p) { return '<span class="p" style="background:' + p.color + '" title="' + esc(p.name) + '">' + esc(M4W.initials(p.name)) + '</span>'; }).join(''));
  }

  function setWriting(on) {
    writing = on;
    if (ed) ed.setReadOnly(!on);
    $root.toggleClass('is-writing', on);
    setStatus();
  }


  // Jodit puts aria-pressed on its list items (not allowed there): keep it on the buttons only.
  function ariaFix(root) {
    var strip = function () { $(root).find('[role="listitem"][aria-pressed]').removeAttr('aria-pressed'); };
    strip();
    if (window.MutationObserver) new MutationObserver(strip).observe(root, { attributes: true, subtree: true, attributeFilter: ['aria-pressed'] });
  }

  function make(html) {
    ed = window.Jodit.make('#m4w-doc-editor', {
      language: document.documentElement.lang === 'fr' ? 'fr' : 'en',
      theme: 'default',
      toolbarSticky: true,
      toolbarButtonSize: 'small',
      buttons: ['undo', 'redo', '|', 'paragraph', 'fontsize', '|', 'bold', 'italic', 'underline', 'strikethrough', 'brush', '|', 'ul', 'ol', 'outdent', 'indent', 'align', '|', 'link', 'image', 'table', 'pagebreak', '|', 'eraser'],
      buttonsMD: ['undo', 'redo', '|', 'paragraph', '|', 'bold', 'italic', 'underline', 'brush', '|', 'ul', 'ol', 'align', '|', 'link', 'image', 'table', 'dots'],
      buttonsSM: ['bold', 'italic', 'underline', '|', 'ul', 'ol', '|', 'paragraph', 'dots'],
      buttonsXS: ['bold', 'italic', 'ul', 'dots'],
      controls: {
        pagebreak: { name: 'pagebreak', icon: 'hr', tooltip: t('doc_pagebreak'), exec: function (editor) { editor.s.insertHTML('<hr class="m4w-pagebreak"><p><br></p>'); } },
        paragraph: { list: { p: t('doc_normal'), h1: t('doc_h1'), h2: t('doc_h2'), h3: t('doc_h3'), h4: t('doc_h4'), blockquote: t('doc_quote') } },
        fontsize: { list: ['8', '9', '10', '11', '12', '14', '16', '18', '20', '24', '28', '36'] }
      },
      defaultFontSizePoints: 'pt',
      statusbar: false, showCharsCounter: false, showWordsCounter: false, showXPathInStatusbar: false,
      spellcheck: true, allowResizeX: false, allowResizeY: false, height: 'auto', minHeight: 900,
      askBeforePasteHTML: false, askBeforePasteFromWord: false, defaultActionOnPaste: 'insert_clear_html',
      uploader: { insertImageAsBase64URI: true }, enableDragAndDropFileToEditor: true,
      disablePlugins: ['speech-recognize', 'ai-assistant', 'about', 'powered-by-jodit', 'print', 'preview', 'file', 'video', 'iframe', 'mobile', 'source'],
      cleanHTML: { denyTags: 'script,iframe,object,embed,form,input,button,style,link,meta' },
      style: { fontFamily: 'Calibri, Carlito, Arial, sans-serif', fontSize: '11pt', lineHeight: '1.4', color: '#111827' },
      readonly: true
    });
    ariaFix(ed.container);
    applying = true; ed.value = html; applying = false;
    ed.events.on('change', function () {
      if (applying || !writing) return;
      pending = true; dirty = true; setStatus();
      clearTimeout(draftTimer); draftTimer = setTimeout(sendDraft, 900);
    });
    ed.events.on('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return false; } });
  }

  function sendDraft() {
    if (!writing || sending) { if (sending) draftTimer = setTimeout(sendDraft, 500); return; }
    pending = false; sending = true; setStatus();
    M4W.post(API + '/draft', { html: ed.value }, { silent: true, quiet: true }).done(function (r) { rev = r.rev; dirty = true; })
      .fail(function (x) {
        var r = x && x.responseJSON;
        if (r && r.pen_lost) { setWriting(false); M4W.toast(r.error, 'warning'); poll(); }
        else { pending = true; draftTimer = setTimeout(sendDraft, 3000); }
      }).always(function () { sending = false; setStatus(); });
  }

  function take() {
    M4W.post(API + '/take', null, { silent: true }).done(function (r) {
      pen = r.pen;
      if (r.ok) { if (r.rev !== rev) { reload(); } setWriting(true); ed.focus(); }
      else { setWriting(false); M4W.toast(t('doc_busy', { name: r.pen.name }), 'info'); }
    });
  }
  function release() {
    var go = function () { M4W.post(API + '/release', null, { silent: true }).always(function () { pen = null; setWriting(false); }); };
    if (pending || sending) { clearTimeout(draftTimer); sendDraft(); setTimeout(release, 600); return; }
    go();
  }
  function save() {
    if (!writing) return;
    if (pending || sending) { clearTimeout(draftTimer); if (!sending) sendDraft(); setTimeout(save, 500); return; }
    M4W.post(API + '/save').done(function (r) { dirty = false; lastPub = Math.floor(Date.now() / 1000); setStatus(); M4W.toast(r.message, 'success'); });
  }
  function reload() {
    return M4W.get(API, null, { silent: true }).done(function (r) {
      rev = r.rev; applying = true; ed.value = r.html; applying = false;
    });
  }

  function poll() {
    clearTimeout(pollTimer);
    M4W.get(API + '/poll', { rev: rev }, { silent: true, quiet: true }).done(function (r) {
      if (r.gone) { window.location.reload(); return; }
      pen = r.pen; people = r.people || []; if (r.published_at) lastPub = r.published_at;
      if (!writing) dirty = r.dirty;
      if (writing && (!pen || !pen.me)) { setWriting(false); M4W.toast(t('doc_pen_lost'), 'warning'); }
      if (r.html !== undefined && !writing) {
        // Follow the writer: new text, same place on the page.
        var top = $desk.scrollTop(); applying = true; ed.value = r.html; applying = false; $desk.scrollTop(top);
        rev = r.rev;
      } else if (writing) { rev = Math.max(rev, r.rev); }
      setStatus();
    }).always(function () { pollTimer = setTimeout(poll, document.hidden ? 10000 : 2000); });
  }

  $root.on('click', '[data-take]', take).on('click', '[data-release]', release).on('click', '[data-save]', save);
  window.addEventListener('pagehide', function () {
    if (writing && (pending || sending) && ed) { try { fetch(M4W.url(API + '/draft'), { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': M4W.csrf() }, body: JSON.stringify({ html: ed.value }) }); } catch (e) { /* closing */ } }
    if (writing) navigator.sendBeacon(M4W.url(API + '/release'), new Blob([JSON.stringify({ _csrf: M4W.csrf() })], { type: 'application/json' }));
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  M4W.get(API, null, { silent: true }).done(function (r) {
    rev = r.rev; me = r.me; canWrite = r.can_write; pen = r.pen; dirty = r.dirty;
    make(r.html);
    var issues = (r.issues || []).map(function (k) { return t('doc_issue_' + k); }).join(' · ');
    var notice = r.mode === 'copy' ? t('doc_copy_notice', { why: issues }) : '';
    $root.find('[data-notice]').toggleClass('d-none', !notice).text(notice);
    $root.find('[data-loading]').addClass('d-none');
    // The pen is taken at once when free: like opening a file in a word processor.
    if (canWrite && (!pen || pen.me)) take(); else setWriting(false);
    poll();
  }).fail(function (x) {
    $root.find('[data-loading]').html('<div class="text-center p-4"><i class="bi bi-exclamation-triangle fs-2 text-warning"></i><p class="mt-2">' + esc((x.responseJSON && x.responseJSON.error) || t('error_network')) + '</p></div>');
  });
})(jQuery);
