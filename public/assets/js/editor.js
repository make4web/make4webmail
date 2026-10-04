/*! Make4Web Mail — rich text editor (TinyMCE, self-hosted in assets/vendor/tinymce) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  var uid = 0;

  var LANG = (document.documentElement.lang || 'fr').slice(0, 2);
  var BASE = M4W.url('assets/vendor/tinymce');

  // Styles applied inside the editing iframe (mail content stays light, like the sent result).
  function contentStyle(font, size) {
    return 'body{font-family:' + (font || 'Arial, Helvetica, sans-serif') + ';font-size:' + (size || '14px') + ';line-height:1.55;color:#1f2937;margin:12px 16px;word-wrap:break-word}'
      + 'p{margin:0 0 .6em}blockquote{margin:0 0 0 .8ex;border-left:2px solid #cbd5e1;padding-left:1ex;color:#475569}'
      + 'img{max-width:100%;height:auto}img.m4w-blocked{min-width:24px;min-height:24px;background:#f1f5f9;outline:1px dashed #cbd5e1}'
      + '.m4w-sig-preview{margin:14px 0;padding:12px 14px;border:1px dashed #cbd5e1;border-radius:8px;position:relative;background:#f8fafc;cursor:default;user-select:none}'
      + '.m4w-sig-preview::before{content:attr(data-label);position:absolute;top:-.65em;left:10px;font:600 10px/1.4 Arial,sans-serif;letter-spacing:.06em;text-transform:uppercase;background:#fff;padding:0 6px;color:#64748b;border-radius:4px}'
      + '.m4w-sig-preview .m4w-sig-content{pointer-events:none;opacity:.92}'
      + '.mce-content-body [contenteditable=false][data-mce-selected]{outline:2px solid #93c5fd}';
  }

  function isDark() { return document.documentElement.getAttribute('data-bs-theme') === 'dark'; }

  /**
   * @param host   container element (an editor host div) or a <textarea>
   * @param opts   placeholder, minimal, font, size, height, onChange(), onImage(file) -> promise(url),
   *               onKey(event) -> true to swallow, onFiles(FileList) for dropped non-image files
   */
  function Editor(host, opts) {
    var self = this;
    this.opts = $.extend({ placeholder: '', minimal: false, onChange: null, onImage: null, onKey: null, onFiles: null, font: '', size: '', height: null }, opts || {});
    this.$host = $(host);
    this.queue = [];
    this.pendingHtml = null;
    var $target = this.$host.is('textarea') ? this.$host : $('<textarea></textarea>').appendTo(this.$host);
    this.$target = $target;
    if (!$target.attr('id')) $target.attr('id', 'm4w-ed-' + (++uid));
    this.id = $target.attr('id');

    var o = this.opts;
    // Most used first: on narrow windows the tail collapses into the "…" overflow menu.
    var full = 'bold italic underline forecolor | bullist numlist | link image emoticons | fontfamily fontsize | align outdent indent blockquote | strikethrough backcolor table charmap | removeformat undo redo code';
    var minimal = 'bold italic underline | forecolor | bullist numlist | link | removeformat';

    window.tinymce.init({
      target: $target[0],
      base_url: BASE,
      suffix: '.min',
      license_key: 'gpl',
      language: LANG === 'fr' ? 'fr-FR' : undefined,
      language_url: LANG === 'fr' ? BASE + '/langs/fr-FR.js' : undefined,
      skin: isDark() ? 'oxide-dark' : 'oxide',
      content_css: 'default',
      content_style: contentStyle(o.font, o.size),
      menubar: false,
      statusbar: false,
      branding: false,
      promotion: false,
      placeholder: o.placeholder,
      height: o.height || (o.minimal ? 220 : '100%'),
      min_height: o.minimal ? 160 : 200,
      resize: false,
      plugins: o.minimal ? 'lists link autolink autoresize' : 'lists advlist link autolink image table emoticons charmap code searchreplace quickbars' + (o.height ? ' autoresize' : ''),
      toolbar: o.minimal ? minimal : full,
      toolbar_mode: 'sliding',
      quickbars_insert_toolbar: false,
      quickbars_selection_toolbar: 'bold italic underline | forecolor | quicklink blockquote',
      font_family_formats: 'Arial=Arial,Helvetica,sans-serif;Calibri=Calibri,Carlito,sans-serif;Georgia=Georgia,serif;Tahoma=Tahoma,Geneva,sans-serif;Times New Roman="Times New Roman",Times,serif;Verdana=Verdana,Geneva,sans-serif;Courier New="Courier New",Courier,monospace',
      font_size_formats: '10px 12px 13px 14px 16px 18px 24px 32px',
      link_default_target: '_blank',
      link_assume_external_targets: 'https',
      relative_urls: false,
      remove_script_host: true,
      convert_urls: false,
      browser_spellcheck: true,
      contextmenu: false,
      table_default_styles: { 'border-collapse': 'collapse', width: '100%' },
      paste_data_images: true,
      automatic_uploads: true,
      images_file_types: 'png,jpg,jpeg,gif,webp',
      image_dimensions: false,
      image_description: true,
      file_picker_types: o.onImage ? 'image' : '',
      file_picker_callback: o.onImage ? function (cb) {
        var $f = $('<input type="file" accept="image/png,image/jpeg,image/gif,image/webp">');
        $f.on('change', function () {
          var file = this.files[0];
          if (file) o.onImage(file).done(function (url) { cb(url, { alt: file.name }); });
        }).trigger('click');
      } : undefined,
      images_upload_handler: o.onImage ? function (blobInfo) {
        return new Promise(function (resolve, reject) {
          var file = new File([blobInfo.blob()], blobInfo.filename(), { type: blobInfo.blob().type });
          o.onImage(file).done(resolve).fail(function () { reject({ message: M4W.t('error_network'), remove: true }); });
        });
      } : undefined,
      extended_valid_elements: 'div[class|style|align|dir|title|contenteditable|data-m4w-quote|data-m4w-q|data-m4w-sig-preview|data-m4w-signature|data-label],img[src|alt|title|width|height|style|class|data-m4w-src],a[href|target|rel|title|style|class]',
      valid_children: '+body[style]',
      setup: function (ed) {
        self.ed = ed;
        ed.on('init', function () {
          if (self.pendingHtml !== null) { ed.setContent(self.pendingHtml); self.pendingHtml = null; ed.undoManager.clear(); }
          self.ready = true;
          var q = self.queue; self.queue = [];
          q.forEach(function (fn) { fn(ed); });
        });
        ed.on('input change undo redo ExecCommand SetContent', function (e) {
          if (e.type === 'setcontent' && !e.paste) return;
          if (o.onChange) o.onChange();
        });
        ed.on('keydown', function (e) {
          if (o.onKey && o.onKey(e) === true) { e.preventDefault(); e.stopPropagation(); }
        });
        ed.on('drop', function (e) {
          var files = e.dataTransfer && e.dataTransfer.files;
          if (!files || !files.length || !o.onFiles) return;
          var others = Array.prototype.filter.call(files, function (f) { return !/^image\//.test(f.type); });
          if (others.length) { e.preventDefault(); o.onFiles(others); }
        });
        // The signature preview block is display-only: never let it reach the saved HTML.
        ed.on('GetContent', function (e) {
          if (e.content && e.content.indexOf('data-m4w-sig-preview') !== -1) {
            var $c = $('<div>').html(e.content);
            $c.find('[data-m4w-sig-preview]').remove();
            e.content = $c.html();
          }
        });
      }
    });
  }

  /** Run fn(editor) now, or as soon as TinyMCE is initialised. */
  Editor.prototype.whenReady = function (fn) {
    if (this.ready && this.ed) fn(this.ed); else this.queue.push(fn);
    return this;
  };
  Editor.prototype.getHTML = function () {
    if (!this.ready) return this.pendingHtml !== null ? this.pendingHtml : this.$target.val();
    return this.ed.getContent();
  };
  Editor.prototype.setHTML = function (html) {
    if (!this.ready) { this.pendingHtml = html || ''; return; }
    this.ed.setContent(html || '');
    this.ed.undoManager.clear();
  };
  Editor.prototype.isEmpty = function () {
    if (!this.ready) return !String(this.pendingHtml || this.$target.val() || '').replace(/<[^>]*>|&nbsp;/g, '').trim();
    var body = this.ed.getBody();
    var $b = $(body).clone();
    $b.find('[data-m4w-sig-preview]').remove();
    return $b.text().trim() === '' && !$b.find('img').length;
  };
  Editor.prototype.focus = function (atStart) {
    return this.whenReady(function (ed) {
      ed.focus();
      ed.selection.select(ed.getBody(), true);
      ed.selection.collapse(!!atStart);
      if (atStart) ed.getWin().scrollTo(0, 0);
    });
  };
  /** jQuery-wrapped editable body (for inserting display-only blocks). */
  Editor.prototype.body = function () { return this.ready ? $(this.ed.getBody()) : $(); };
  Editor.prototype.exec = function (cmd) {
    return this.whenReady(function (ed) {
      if (cmd === 'image') ed.execCommand('mceImage');
      else if (cmd === 'link') ed.execCommand('mceLink');
      else ed.execCommand(cmd);
    });
  };
  Editor.prototype.toggleToolbar = function (show) {
    this.$host.toggleClass('m4w-ed-no-toolbar', show === undefined ? undefined : !show);
  };
  Editor.prototype.destroy = function () {
    if (this.ed) { try { this.ed.remove(); } catch (e) {} }
    this.ed = null; this.ready = false;
  };

  M4W.Editor = Editor;

  // Settings pages: <textarea data-editor> / <textarea data-editor="minimal">.
  $(function () {
    if (!window.tinymce) return;
    $('textarea[data-editor]').each(function () {
      var $ta = $(this);
      var ed = new Editor($ta, { minimal: $ta.data('editor') === 'minimal', placeholder: $ta.attr('placeholder') || '', height: $ta.data('editor') === 'minimal' ? 200 : 320 });
      $ta.data('m4wEditor', ed);
      $ta.closest('form').on('submit', function () { if (ed.ed) ed.ed.save(); });
    });
  });
})(jQuery, window, document);
