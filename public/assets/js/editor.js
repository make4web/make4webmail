/*! Make4Web Mail — lightweight rich text editor (contenteditable) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  var t = M4W.t;

  var COLORS = ['#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#d9d9d9', '#efefef', '#ffffff',
    '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff',
    '#9900ff', '#ff00ff', '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3',
    '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc', '#dc2626', '#16a34a', '#2563eb', '#7c3aed'];
  var FONTS = [['Arial, Helvetica, sans-serif', 'Arial'], ['Calibri, Carlito, sans-serif', 'Calibri'], ['Georgia, serif', 'Georgia'],
    ['Tahoma, Geneva, sans-serif', 'Tahoma'], ['"Times New Roman", Times, serif', 'Times New Roman'], ['Verdana, Geneva, sans-serif', 'Verdana'],
    ['"Courier New", Courier, monospace', 'Courier New']];
  var SIZES = [['1', 'small'], ['3', 'normal'], ['4', 'large'], ['6', 'huge']];

  function Editor(container, opts) {
    this.opts = $.extend({ placeholder: '', minimal: false, onChange: null, onImage: null, toolbarBottom: true, font: 'Arial, Helvetica, sans-serif', size: '14px' }, opts || {});
    this.$wrap = $(container);
    this.build();
  }

  Editor.prototype.build = function () {
    var self = this, o = this.opts;
    this.$area = $('<div class="m4w-editor" contenteditable="true" role="textbox" aria-multiline="true" spellcheck="true"></div>')
      .attr('data-placeholder', o.placeholder).css({ fontFamily: o.font, fontSize: o.size });
    this.$toolbar = $('<div class="m4w-editor-toolbar" role="toolbar"></div>');
    var b = function (cmd, icon, title, extra) {
      return '<button type="button" class="btn btn-sm" data-cmd="' + cmd + '"' + (extra || '') + ' title="' + M4W.esc(title) + '" aria-label="' + M4W.esc(title) + '"><i class="bi bi-' + icon + '"></i></button>';
    };
    var html = '';
    if (!o.minimal) {
      html += b('undo', 'arrow-counterclockwise', t('ed.undo') + ' (Ctrl+Z)') + b('redo', 'arrow-clockwise', t('ed.redo') + ' (Ctrl+Y)') + '<span class="sep"></span>';
      html += '<select data-font title="' + M4W.esc(t('ed.font')) + '">' + FONTS.map(function (f) { return '<option value=\'' + f[0] + '\'>' + f[1] + '</option>'; }).join('') + '</select>';
      html += '<select data-size title="' + M4W.esc(t('ed.size')) + '">' + SIZES.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === '3' ? ' selected' : '') + '>' + M4W.esc(t('ed.size_' + s[1])) + '</option>'; }).join('') + '</select><span class="sep"></span>';
    }
    html += b('bold', 'type-bold', t('ed.bold') + ' (Ctrl+B)') + b('italic', 'type-italic', t('ed.italic') + ' (Ctrl+I)') + b('underline', 'type-underline', t('ed.underline') + ' (Ctrl+U)');
    if (!o.minimal) html += b('strikeThrough', 'type-strikethrough', t('ed.strike'));
    html += '<div class="dropdown d-inline-block"><button type="button" class="btn btn-sm" data-bs-toggle="dropdown" title="' + M4W.esc(t('ed.color')) + '"><i class="bi bi-palette"></i></button><div class="dropdown-menu p-0">'
      + '<div class="px-2 pt-2 small text-muted">' + M4W.esc(t('ed.text_color')) + '</div><div class="m4w-colors" data-colors="foreColor">' + COLORS.map(function (c) { return '<button type="button" data-color="' + c + '" style="background:' + c + '"></button>'; }).join('') + '</div>'
      + '<div class="px-2 small text-muted">' + M4W.esc(t('ed.bg_color')) + '</div><div class="m4w-colors" data-colors="hiliteColor">' + COLORS.map(function (c) { return '<button type="button" data-color="' + c + '" style="background:' + c + '"></button>'; }).join('') + '</div></div></div>';
    html += '<span class="sep"></span>' + b('insertUnorderedList', 'list-ul', t('ed.ul')) + b('insertOrderedList', 'list-ol', t('ed.ol'));
    if (!o.minimal) {
      html += '<div class="dropdown d-inline-block"><button type="button" class="btn btn-sm" data-bs-toggle="dropdown" title="' + M4W.esc(t('ed.align')) + '"><i class="bi bi-text-left"></i></button><div class="dropdown-menu p-1" style="min-width:auto"><div class="d-flex">'
        + b('justifyLeft', 'text-left', t('ed.left')) + b('justifyCenter', 'text-center', t('ed.center')) + b('justifyRight', 'text-right', t('ed.right')) + b('justifyFull', 'justify', t('ed.justify')) + '</div></div></div>';
      html += b('outdent', 'text-indent-right', t('ed.outdent')) + b('indent', 'text-indent-left', t('ed.indent')) + b('formatBlock', 'quote', t('ed.quote'), ' data-value="blockquote"');
    }
    html += '<span class="sep"></span>' + b('link', 'link-45deg', t('ed.link') + ' (Ctrl+K)');
    if (o.onImage) html += b('image', 'image', t('ed.image'));
    if (!o.minimal) html += b('insertHorizontalRule', 'dash-lg', t('ed.hr'));
    html += b('removeFormat', 'eraser', t('ed.clear'));
    this.$toolbar.html(html);

    this.$wrap.addClass('m4w-editor-host');
    if (o.toolbarBottom) { this.$wrap.append(this.$area); } else { this.$wrap.append(this.$toolbar).append(this.$area); }
    this.$file = $('<input type="file" accept="image/*" class="d-none">').appendTo(this.$wrap);

    this.$toolbar.on('mousedown', 'button[data-cmd], .m4w-colors button', function (e) { e.preventDefault(); });
    this.$toolbar.on('click', 'button[data-cmd]', function () { self.exec($(this).data('cmd'), $(this).data('value')); });
    this.$toolbar.on('click', '.m4w-colors button', function () {
      var cmd = $(this).parent().data('colors');
      self.restore();
      document.execCommand('styleWithCSS', false, true);
      document.execCommand(cmd, false, $(this).data('color'));
      if (cmd === 'hiliteColor' && !document.queryCommandSupported('hiliteColor')) document.execCommand('backColor', false, $(this).data('color'));
      self.changed();
    });
    this.$toolbar.on('change', 'select[data-font]', function () { self.restore(); document.execCommand('fontName', false, this.value); self.changed(); });
    this.$toolbar.on('change', 'select[data-size]', function () { self.restore(); document.execCommand('fontSize', false, this.value); self.changed(); });
    this.$file.on('change', function () { if (this.files[0]) self.insertImageFile(this.files[0]); this.value = ''; });

    this.$area.on('input', function () { self.changed(); })
      .on('keyup mouseup', function () { self.save(); self.state(); })
      .on('blur', function () { self.save(); })
      .on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); self.exec('link'); }
      })
      .on('paste', function (e) { self.onPaste(e); })
      .on('drop', function (e) {
        var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
        if (files && files.length && /^image\//.test(files[0].type) && self.opts.onImage && !e.originalEvent.dataTransfer.types.includes('text/html')) {
          // Inline image drop handled by the editor only when the host doesn't treat it as attachment.
        }
      })
      .on('click', 'a', function (e) { if (e.ctrlKey || e.metaKey) window.open(this.href, '_blank', 'noopener'); });
  };

  Editor.prototype.save = function () {
    var sel = window.getSelection();
    if (sel.rangeCount && this.$area[0].contains(sel.anchorNode)) this.range = sel.getRangeAt(0).cloneRange();
  };
  Editor.prototype.restore = function () {
    this.$area.trigger('focus');
    if (this.range) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(this.range); }
  };
  Editor.prototype.state = function () {
    this.$toolbar.find('button[data-cmd]').each(function () {
      var c = $(this).data('cmd');
      if (['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].indexOf(c) !== -1) {
        try { $(this).toggleClass('active', document.queryCommandState(c)); } catch (e) {}
      }
    });
  };
  Editor.prototype.exec = function (cmd, value) {
    var self = this;
    this.restore();
    if (cmd === 'link') {
      var sel = window.getSelection(), current = '';
      var a = sel.anchorNode && $(sel.anchorNode).closest('a', this.$area[0])[0];
      if (a) current = a.getAttribute('href');
      var url = window.prompt(t('ed.link_prompt'), current || 'https://');
      if (url === null) return;
      url = url.trim();
      if (url === '' || url === 'https://') { document.execCommand('unlink'); this.changed(); return; }
      if (!/^(https?:|mailto:|tel:)/i.test(url)) url = (url.indexOf('@') > 0 ? 'mailto:' : 'https://') + url;
      if (sel.isCollapsed && !a) { document.execCommand('insertHTML', false, '<a href="' + M4W.esc(url) + '">' + M4W.esc(url.replace(/^mailto:/, '')) + '</a>&nbsp;'); }
      else { document.execCommand('createLink', false, url); }
    } else if (cmd === 'image') {
      this.$file.trigger('click');
      return;
    } else if (cmd === 'formatBlock') {
      document.execCommand('formatBlock', false, value);
    } else {
      document.execCommand(cmd, false, value || null);
    }
    this.changed();
    this.state();
    setTimeout(function () { self.save(); }, 0);
  };
  Editor.prototype.insertImageFile = function (file) {
    var self = this;
    if (!/^image\//.test(file.type)) return;
    if (this.opts.onImage) {
      this.opts.onImage(file).done(function (url) { self.restore(); document.execCommand('insertHTML', false, '<img src="' + M4W.esc(url) + '" style="max-width:100%">'); self.changed(); });
    }
  };
  Editor.prototype.onPaste = function (e) {
    var cd = e.originalEvent.clipboardData;
    if (!cd) return;
    var self = this;
    if (cd.files && cd.files.length && /^image\//.test(cd.files[0].type)) {
      e.preventDefault();
      if (this.opts.onImage) { this.insertImageFile(cd.files[0]); return; }
      var reader = new FileReader();
      reader.onload = function () { document.execCommand('insertHTML', false, '<img src="' + reader.result + '" style="max-width:100%">'); self.changed(); };
      reader.readAsDataURL(cd.files[0]);
      return;
    }
    var html = cd.getData('text/html');
    if (html) {
      e.preventDefault();
      document.execCommand('insertHTML', false, Editor.cleanPaste(html));
      this.changed();
    }
  };
  Editor.cleanPaste = function (html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    $(doc).find('script,style,meta,link,iframe,object,embed,form,input,button,textarea,select,o\\:p,xml,title').remove();
    $(doc.body).find('*').each(function () {
      var el = this;
      Array.prototype.slice.call(el.attributes).forEach(function (a) {
        var n = a.name.toLowerCase();
        if (n.indexOf('on') === 0 || ['class', 'id', 'lang', 'data-start', 'data-end'].indexOf(n) !== -1 || n.indexOf('data-') === 0 && n !== 'data-m4w-src') el.removeAttribute(a.name);
        if ((n === 'href' || n === 'src') && /^\s*(javascript|vbscript|data:(?!image\/))/i.test(a.value)) el.removeAttribute(a.name);
      });
      // Drop Office mso-* noise.
      if (el.style) { for (var i = el.style.length - 1; i >= 0; i--) { if (/^mso-/.test(el.style[i])) el.style.removeProperty(el.style[i]); } }
    });
    return doc.body.innerHTML.replace(/<!--[\s\S]*?-->/g, '');
  };
  Editor.prototype.changed = function () { if (this.opts.onChange) this.opts.onChange(); };
  Editor.prototype.getHTML = function () {
    var html = this.$area.html();
    return html === '<br>' ? '' : html;
  };
  Editor.prototype.setHTML = function (html) { this.$area.html(html || ''); };
  Editor.prototype.isEmpty = function () { return String(this.$area.text()).trim() === '' && !this.$area.find('img').length; };
  Editor.prototype.focus = function (atStart) {
    var el = this.$area[0];
    el.focus();
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(!!atStart);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(range);
    if (atStart) el.scrollTop = 0;
  };
  Editor.prototype.toolbar = function () { return this.$toolbar; };

  M4W.Editor = Editor;

  // Auto-init textareas marked data-editor (settings pages): keeps the textarea in sync.
  $(function () {
    $('textarea[data-editor]').each(function () {
      var $ta = $(this).addClass('d-none');
      var $host = $('<div class="border rounded-3 overflow-hidden d-flex flex-column" style="min-height:220px;background:var(--m4w-surface)"></div>').insertAfter($ta);
      var ed = new Editor($host, { minimal: $ta.data('editor') === 'minimal', toolbarBottom: false, placeholder: $ta.attr('placeholder') || '', onChange: function () { $ta.val(ed.getHTML()); } });
      $host.prepend(ed.toolbar());
      ed.$toolbar.css({ borderTop: 0, borderBottom: '1px solid var(--m4w-border)' });
      ed.setHTML($ta.val());
      $ta.closest('form').on('submit', function () { $ta.val(ed.getHTML()); });
    });
  });
})(jQuery, window, document);
