/*! Make4WebMail — rich text editor (Jodit, MIT, self-hosted in assets/vendor/jodit) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  var uid = 0;
  var LANG = (document.documentElement.lang || 'fr').slice(0, 2);

  function isDark() { return document.documentElement.getAttribute('data-bs-theme') === 'dark'; }

  /**
   * Same API as before (compose window, vacation, personal signature):
   * getHTML / setHTML / isEmpty / focus / body / exec / whenReady / toggleToolbar / destroy.
   * @param host  container element or a <textarea>
   * @param opts  placeholder, minimal, font, size, height, onChange(), onImage(file) -> promise(url),
   *              onKey(event) -> true to swallow, onFiles(files) for dropped non-image files
   */
  function Editor(host, opts) {
    var self = this;
    var o = this.opts = $.extend({ placeholder: '', minimal: false, onChange: null, onImage: null, onKey: null, onFiles: null, font: '', size: '', height: null }, opts || {});
    this.$host = $(host);
    var $target = this.$host.is('textarea') ? this.$host : $('<textarea></textarea>').appendTo(this.$host);
    if (!$target.attr('id')) $target.attr('id', 'm4w-ed-' + (++uid));
    this.$target = $target;

    var full = ['bold', 'italic', 'underline', 'brush', '|', 'ul', 'ol', '|', 'link', 'image', '|', 'font', 'fontsize', '|',
      'align', 'outdent', 'indent', 'paragraph', '|', 'strikethrough', 'table', 'symbols', 'hr', '|', 'eraser', 'undo', 'redo', 'source'];
    var minimal = ['bold', 'italic', 'underline', 'brush', '|', 'ul', 'ol', '|', 'link', '|', 'eraser'];

    var config = {
      language: LANG === 'fr' ? 'fr' : 'en',
      theme: isDark() ? 'dark' : 'default',
      placeholder: o.placeholder,
      toolbarButtonSize: 'middle',
      buttons: o.minimal ? minimal : full,
      buttonsMD: o.minimal ? minimal : ['bold', 'italic', 'underline', 'brush', '|', 'ul', 'ol', '|', 'link', 'image', '|', 'font', 'fontsize', 'align', '|', 'eraser', 'dots'],
      buttonsSM: o.minimal ? minimal : ['bold', 'italic', 'underline', 'brush', '|', 'ul', 'ol', '|', 'link', 'image', '|', 'dots'],
      buttonsXS: ['bold', 'italic', 'brush', 'ul', 'link', 'dots'],
      toolbarAdaptive: true,
      toolbarSticky: false,
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      statusbar: false,
      allowResizeX: false,
      allowResizeY: false,
      spellcheck: true,
      height: o.height || 'auto',
      minHeight: o.minimal ? 160 : 220,
      askBeforePasteHTML: false,
      askBeforePasteFromWord: false,
      defaultActionOnPaste: 'insert_clear_html',
      enableDragAndDropFileToEditor: !!o.onImage,
      disablePlugins: ['speech-recognize', 'ai-assistant', 'about', 'powered-by-jodit', 'print', 'preview', 'file', 'video', 'iframe', 'mobile'],
      link: { openInNewTabCheckbox: true, noFollowCheckbox: false, modeClassName: false },
      style: { fontFamily: o.font || 'Arial, Helvetica, sans-serif', fontSize: o.size || '14px', lineHeight: '1.55', color: '#1f2937' },
      controls: {
        font: { list: { 'Arial,Helvetica,sans-serif': 'Arial', 'Calibri,Carlito,sans-serif': 'Calibri', 'Georgia,serif': 'Georgia', 'Tahoma,Geneva,sans-serif': 'Tahoma', '"Times New Roman",Times,serif': 'Times New Roman', 'Verdana,Geneva,sans-serif': 'Verdana', '"Courier New",Courier,monospace': 'Courier New' } },
        fontsize: { list: ['10', '12', '13', '14', '16', '18', '24', '32'] }
      },
      cleanHTML: { denyTags: 'script,iframe,object,embed,form,input,button,style,link,meta' },
      uploader: o.onImage ? {
        // All uploads go through the webmail API (CSRF-protected); the server turns them into CID parts.
        insertImageAsBase64URI: false,
        url: M4W.url('api/upload'),
        format: 'json',
        method: 'POST',
        filesVariableName: function () { return 'file'; },
        headers: { 'X-CSRF-Token': M4W.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        prepareData: function (formData) { return formData; },
        isSuccess: function (resp) { return resp && resp.ok; },
        getMessage: function (resp) { return (resp && resp.error) || M4W.t('error_network'); },
        process: function (resp) { return { files: resp.ok ? [resp.file.url] : [], baseurl: '', isImages: [true], error: resp.ok ? 0 : 1, msg: resp.error || '' }; }
      } : { insertImageAsBase64URI: true }
    };

    var ed = this.ed = window.Jodit.make($target[0], config);
    this.ready = true;
    this.$container = $(ed.container);

    ed.events.on('change', function () { if (o.onChange) o.onChange(); });
    ed.events.on('keydown', function (e) {
      if (o.onKey && o.onKey(e) === true) { e.preventDefault(); return false; }
    });
    // Non-image files dropped on the text become attachments (capture phase, before Jodit).
    if (o.onFiles) {
      ed.container.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        var others = Array.prototype.filter.call(files, function (f) { return !/^image\//.test(f.type); });
        if (others.length) { e.preventDefault(); e.stopPropagation(); o.onFiles(others); }
      }, true);
    }
  }

  Editor.prototype.whenReady = function (fn) { fn(this.ed); return this; };
  Editor.prototype.getHTML = function () {
    var html = this.ed.value || '';
    if (html.indexOf('data-m4w-sig-preview') !== -1) {
      var $c = $('<div>').html(html);
      $c.find('[data-m4w-sig-preview]').remove();
      html = $c.html();
    }
    return html === '<p><br></p>' ? '' : html;
  };
  Editor.prototype.setHTML = function (html) {
    this.ed.value = html || '';
    if (this.ed.history) this.ed.history.clear();
  };
  Editor.prototype.isEmpty = function () {
    var $b = $(this.ed.editor).clone();
    $b.find('[data-m4w-sig-preview]').remove();
    return $b.text().trim() === '' && !$b.find('img').length;
  };
  Editor.prototype.focus = function (atStart) {
    var ed = this.ed;
    ed.s.focus();
    var range = ed.ed.createRange();
    range.selectNodeContents(ed.editor);
    range.collapse(!!atStart);
    ed.s.selectRange(range);
    if (atStart) ed.editor.scrollTop = 0;
    return this;
  };
  /** jQuery-wrapped editable element (for inserting display-only blocks). */
  Editor.prototype.body = function () { return $(this.ed.editor); };
  Editor.prototype.exec = function (cmd) {
    var self = this, ed = this.ed;
    if (cmd === 'image') {
      if (!this.opts.onImage) return this;
      var $f = $('<input type="file" accept="image/png,image/jpeg,image/gif,image/webp">');
      var saved = ed.s.save();
      $f.on('change', function () {
        var file = this.files[0];
        if (!file) return;
        self.opts.onImage(file).done(function (url) { ed.s.restore(saved); ed.s.insertImage(url, null, 'auto'); });
      }).trigger('click');
    } else if (cmd === 'link') {
      var text = ed.s.sel ? String(ed.s.sel) : '';
      var url = window.prompt(M4W.t('ed_link_prompt'), 'https://');
      if (!url || url === 'https://') return this;
      if (!/^(https?:|mailto:|tel:)/i.test(url)) url = (url.indexOf('@') > 0 ? 'mailto:' : 'https://') + url;
      ed.s.focus();
      if (text) ed.execCommand('createLink', false, url);
      else ed.s.insertHTML('<a href="' + M4W.esc(url) + '" target="_blank">' + M4W.esc(url.replace(/^mailto:/, '')) + '</a>&nbsp;');
    } else {
      ed.execCommand(cmd);
    }
    return this;
  };
  Editor.prototype.toggleToolbar = function (show) {
    this.$host.toggleClass('m4w-ed-no-toolbar', show === undefined ? undefined : !show);
  };
  Editor.prototype.destroy = function () {
    if (this.ed) { try { this.ed.destruct(); } catch (e) {} }
    this.ed = null;
    this.ready = false;
  };

  M4W.Editor = Editor;

  // Settings pages: <textarea data-editor> / <textarea data-editor="minimal">.
  $(function () {
    if (!window.Jodit) return;
    $('textarea[data-editor]').each(function () {
      var $ta = $(this), min = $ta.data('editor') === 'minimal';
      var ed = new Editor($ta, { minimal: min, placeholder: $ta.attr('placeholder') || '', height: min ? 200 : 320 });
      $ta.data('m4wEditor', ed);
      $ta.closest('form').on('submit', function () { $ta.val(ed.getHTML()); });
    });
  });
})(jQuery, window, document);
