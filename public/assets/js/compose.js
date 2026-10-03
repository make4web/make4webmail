/*! Make4Web Mail — compose window */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;
  var EMAIL_RE = /^[^\s@<>(),;:"\[\]]+@[^\s@<>(),;:"\[\]]+\.[^\s@<>(),;:"\[\]]{2,}$/;

  // ------------------------------------------------------- address parsing
  function parseAddresses(str) {
    var out = [], buf = '', q = false, depth = 0;
    str = String(str || '');
    for (var i = 0; i < str.length; i++) {
      var c = str[i];
      if (c === '"') q = !q;
      if (!q && c === '<') depth++;
      if (!q && c === '>') depth = Math.max(0, depth - 1);
      if (!q && depth === 0 && (c === ',' || c === ';' || c === '\n')) { push(buf); buf = ''; continue; }
      buf += c;
    }
    push(buf);
    function push(s) {
      s = String(s).trim();
      if (!s) return;
      var m = s.match(/^(.*)<([^>]+)>\s*$/);
      if (m) out.push({ name: String(m[1]).trim().replace(/^"|"$/g, ''), email: String(m[2]).trim() });
      else s.split(/\s+/).forEach(function (p) { if (p) out.push({ name: '', email: p.replace(/^<|>$/g, '') }); });
    }
    return out;
  }
  function fmt(a) { return a.name ? '"' + a.name.replace(/"/g, '') + '" <' + a.email + '>' : a.email; }

  // ---------------------------------------------------- recipient widget
  function Recipients($host, onChange) {
    var self = this;
    this.list = [];
    this.$host = $host;
    this.$input = $('<input type="text" autocomplete="off" spellcheck="false">').attr('aria-label', $host.data('label'));
    this.$suggest = $('<div class="m4w-suggest d-none" role="listbox"></div>');
    $host.append(this.$input).append(this.$suggest);
    this.onChange = onChange;
    var lookup = M4W.debounce(function () { self.lookup(); }, 140);
    this.$input.on('input', function () {
      if (/[,;]\s*$/.test(this.value)) { self.commit(); return; }
      lookup();
    }).on('keydown', function (e) {
      var $items = self.$suggest.find('.m4w-suggest-item'), $act = $items.filter('.active');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!$items.length) return;
        e.preventDefault();
        var idx = $items.index($act) + (e.key === 'ArrowDown' ? 1 : -1);
        idx = (idx + $items.length) % $items.length;
        $items.removeClass('active').eq(idx).addClass('active');
      } else if (e.key === 'Enter' || (e.key === 'Tab' && this.value)) {
        if ($act.length) { e.preventDefault(); self.pick($act.data('item')); }
        else if (this.value) { e.preventDefault(); self.commit(); }
      } else if (e.key === 'Backspace' && !this.value && self.list.length) {
        var last = self.list.pop(); self.render(); this.value = fmt(last); self.changed();
      } else if (e.key === 'Escape') {
        if (!self.$suggest.hasClass('d-none')) { e.stopPropagation(); self.hide(); }
      }
    }).on('blur', function () { setTimeout(function () { self.hide(); if (self.$input.val()) self.commit(); }, 180); })
      .on('paste', function (e) {
        var text = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
        if (/[,;\n]/.test(text) || parseAddresses(text).length > 1) { e.preventDefault(); self.add(parseAddresses(text)); }
      });
    $host.on('click', function (e) { if (e.target === $host[0]) self.$input.trigger('focus'); });
    $host.on('click', '.m4w-rcpt button', function (e) { e.stopPropagation(); self.list.splice(+$(this).parent().data('i'), 1); self.render(); self.changed(); });
    $host.on('dblclick', '.m4w-rcpt', function () {
      var i = +$(this).data('i'), a = self.list.splice(i, 1)[0]; self.render(); self.$input.val(fmt(a)).trigger('focus'); self.changed();
    });
    this.$suggest.on('mousedown', '.m4w-suggest-item', function (e) { e.preventDefault(); self.pick($(this).data('item')); });
  }
  Recipients.prototype.lookup = function () {
    var self = this, q = String(this.$input.val()).trim();
    if (q.length < 1) { this.hide(); return; }
    if (this.req) this.req.abort();
    this.req = M4W.get('api/contacts/suggest', { q: q }, { silent: true, quiet: true }).done(function (r) {
      if (!r.items.length) { self.hide(); return; }
      self.$suggest.html(r.items.map(function (it, i) {
        return '<div class="m4w-suggest-item' + (i === 0 ? ' active' : '') + '" role="option">' + M4W.avatar(it.name || it.email, it.email, 'sm')
          + '<div class="min-w-0"><div class="name text-truncate">' + esc(it.name || it.email) + (it.type === 'directory' ? ' <i class="bi bi-building text-muted small" title="' + esc(t('directory')) + '"></i>' : '') + '</div><div class="email text-truncate">' + esc(it.email) + (it.title ? ' · ' + esc(it.title) : '') + '</div></div></div>';
      }).join('')).removeClass('d-none');
      self.$suggest.find('.m4w-suggest-item').each(function (i) { $(this).data('item', r.items[i]); });
    });
  };
  Recipients.prototype.hide = function () { this.$suggest.addClass('d-none').empty(); };
  Recipients.prototype.pick = function (item) { this.$input.val(''); this.hide(); this.add([{ name: item.name, email: item.email }]); this.$input.trigger('focus'); };
  Recipients.prototype.commit = function () { var v = this.$input.val().replace(/[,;]\s*$/, ''); this.$input.val(''); this.hide(); if (String(v).trim()) this.add(parseAddresses(v)); };
  Recipients.prototype.add = function (arr) {
    var self = this;
    arr.forEach(function (a) {
      if (!a.email) return;
      var exists = self.list.some(function (x) { return x.email.toLowerCase() === a.email.toLowerCase(); });
      if (!exists) self.list.push(a);
    });
    this.render(); this.changed();
  };
  Recipients.prototype.set = function (str) { this.list = []; this.add(parseAddresses(str)); };
  Recipients.prototype.render = function () {
    this.$host.find('.m4w-rcpt').remove();
    var html = this.list.map(function (a, i) {
      var invalid = !EMAIL_RE.test(a.email);
      return '<span class="m4w-rcpt' + (invalid ? ' invalid' : '') + '" data-i="' + i + '" title="' + esc(a.email) + '">' + M4W.avatar(a.name || a.email, a.email) + '<span>' + esc(a.name || a.email) + '</span><button type="button" aria-label="' + esc(t('remove')) + '"><i class="bi bi-x"></i></button></span>';
    }).join('');
    this.$input.before(html);
  };
  Recipients.prototype.value = function () { if (this.$input.val()) this.commit(); return this.list.map(fmt).join(', '); };
  Recipients.prototype.invalid = function () { return this.list.filter(function (a) { return !EMAIL_RE.test(a.email); }); };
  Recipients.prototype.changed = function () { if (this.onChange) this.onChange(); };

  // ------------------------------------------------------------- compose
  var C = null;

  function template() {
    var ids = (M4W.state.identities || []);
    var fromField = ids.length > 1 ? '<div class="m4w-field"><label>' + esc(t('from')) + '</label><select class="plain" name="from">' + ids.map(function (i) { return '<option value="' + esc(i.email) + '">' + esc(i.name + ' <' + i.email + '>') + '</option>'; }).join('') + '</select></div>' : '';
    return '<div class="m4w-compose" role="dialog" aria-label="' + esc(t('new_message')) + '">'
      + '<div class="m4w-compose-head" data-c="toggle-min"><span class="title">' + esc(t('new_message')) + '</span>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="min" title="' + esc(t('minimize')) + '"><i class="bi bi-dash-lg"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-icon m4w-desktop-only" data-c="max" title="' + esc(t('fullscreen')) + '"><i class="bi bi-arrows-angle-expand"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="close" title="' + esc(t('save_close')) + '"><i class="bi bi-x-lg"></i></button></div>'
      + '<div class="m4w-compose-body">' + fromField
      + '<div class="m4w-field"><label>' + esc(t('to')) + '</label><div class="m4w-recipients" data-r="to" data-label="' + esc(t('to')) + '"></div><div class="m4w-field-toggles"><button type="button" class="btn btn-ghost" data-c="cc">Cc</button><button type="button" class="btn btn-ghost" data-c="bcc">' + esc(t('bcc')) + '</button></div></div>'
      + '<div class="m4w-field d-none" data-f="cc"><label>Cc</label><div class="m4w-recipients" data-r="cc" data-label="Cc"></div></div>'
      + '<div class="m4w-field d-none" data-f="bcc"><label>' + esc(t('bcc')) + '</label><div class="m4w-recipients" data-r="bcc" data-label="' + esc(t('bcc')) + '"></div></div>'
      + '<div class="m4w-field"><input class="plain" name="subject" placeholder="' + esc(t('subject')) + '" aria-label="' + esc(t('subject')) + '" maxlength="500"></div>'
      + '<div class="m4w-editor-wrap"><div class="m4w-dropzone"><i class="bi bi-cloud-arrow-up"></i>' + esc(t('drop_files')) + '</div><div data-editor-host class="d-flex flex-column flex-grow-1"></div>'
      + '<div class="m4w-sig-preview d-none" data-sig-out data-label="' + esc(t('signature_auto')) + '"><div class="m4w-sig-content"></div></div>'
      + '<div class="m4w-compose-atts" data-atts></div></div>'
      + '<div data-toolbar-slot class="d-none"></div>'
      + '</div>'
      + '<div class="m4w-compose-footer">'
      + '<div class="btn-group m4w-send-group"><button type="button" class="btn btn-primary m4w-send" data-c="send" title="' + esc(t('send')) + ' (Ctrl+Enter)">' + esc(t('send')) + '</button>'
      + '<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="' + esc(t('send_options')) + '"></button>'
      + '<div class="dropdown-menu"><label class="dropdown-item"><input type="checkbox" class="form-check-input m-0 me-2" data-opt="priority"> <i class="bi bi-exclamation-circle text-danger"></i>' + esc(t('high_priority')) + '</label>'
      + '<label class="dropdown-item"><input type="checkbox" class="form-check-input m-0 me-2" data-opt="receipt"> <i class="bi bi-check2-square"></i>' + esc(t('read_receipt')) + '</label></div></div>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="format" title="' + esc(t('formatting')) + '"><i class="bi bi-type"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="attach" title="' + esc(t('attach')) + '"><i class="bi bi-paperclip"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="image" title="' + esc(t('insert_image')) + '"><i class="bi bi-image"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-icon" data-c="link" title="' + esc(t('insert_link')) + '"><i class="bi bi-link-45deg"></i></button>'
      + '<span class="m4w-saved" data-saved></span>'
      + '<button type="button" class="btn btn-ghost btn-icon ms-auto" data-c="discard" title="' + esc(t('discard')) + '"><i class="bi bi-trash3"></i></button>'
      + '<input type="file" multiple class="d-none" data-file></div></div>';
  }

  function Compose(opts) {
    var self = this;
    this.opts = opts;
    this.state = { mode: 'new', ref_id: 0, draft_id: 0, attachments: [], ref_attachments: [], dirty: false, sending: false };
    this.$el = $(template()).appendTo('body');
    var prefs = M4W.state.prefs || {};
    this.editor = new M4W.Editor(this.$el.find('[data-editor-host]'), {
      placeholder: t('compose_placeholder'), font: prefs.compose_font, size: prefs.compose_size,
      onChange: function () { self.touch(); }, onImage: function (file) { return self.uploadInline(file); }
    });
    this.$el.find('[data-toolbar-slot]').append(this.editor.toolbar());
    this.rcpt = {};
    this.$el.find('[data-r]').each(function () { self.rcpt[$(this).data('r')] = new Recipients($(this), function () { self.touch(); }); });
    this.bind();
    if (window.matchMedia('(max-width: 991.98px)').matches) this.$el.addClass('mobile');
    this.load(opts);
  }

  Compose.prototype.load = function (opts) {
    var self = this;
    var params = { mode: opts.mode || 'new' };
    if (opts.id) params.id = opts.id;
    if (opts.to) params.to = opts.to;
    if (opts.subject) params.subject = opts.subject;
    this.$el.find('.title').text(t('loading'));
    M4W.get('api/compose', params).done(function (r) {
      var c = r.compose;
      self.state.mode = c.mode; self.state.ref_id = c.ref_id; self.state.draft_id = c.draft_id;
      self.state.attachments = c.attachments || [];
      self.state.ref_attachments = c.ref_attachments || [];
      self.state.attach_original = !!opts.attachOriginal;
      if (opts.attachOriginal) self.state.ref_attachments = [];
      self.rcpt.to.set(c.to); self.rcpt.cc.set(c.cc); self.rcpt.bcc.set(c.bcc);
      if (c.cc) self.showField('cc'); if (c.bcc) self.showField('bcc');
      self.$el.find('[name=subject]').val(c.subject);
      self.$el.find('[name=from]').val(c.from);
      self.$el.find('[data-opt=priority]').prop('checked', +c.priority === 1);
      self.editor.setHTML(c.html || '');
      self.signature = r.signature || '';
      self.placeSignature();
      self.renderAtts();
      self.updateTitle();
      self.state.dirty = false;
      if (!c.to) self.rcpt.to.$input.trigger('focus');
      else if (!c.subject) self.$el.find('[name=subject]').trigger('focus');
      else self.editor.focus(true);
    }).fail(function () { self.destroy(); });
  };

  Compose.prototype.placeSignature = function () {
    var $area = this.editor.$area;
    $area.find('[data-m4w-sig-preview]').remove();
    var $out = this.$el.find('[data-sig-out]').addClass('d-none');
    if (!this.signature) return;
    var $quote = $area.find('[data-m4w-quote]').first();
    if ($quote.length) {
      $('<div class="m4w-sig-preview" contenteditable="false" data-m4w-sig-preview></div>').attr('data-label', t('signature_auto'))
        .append($('<div class="m4w-sig-content"></div>').html(this.signature)).insertBefore($quote);
    } else {
      $out.removeClass('d-none').find('.m4w-sig-content').html(this.signature);
    }
  };

  Compose.prototype.bodyHtml = function () {
    var $c = $('<div>').html(this.editor.getHTML());
    $c.find('[data-m4w-sig-preview]').remove();
    var html = $c.html();
    var p = M4W.state.prefs || {};
    if (!String($c.text()).trim() && !$c.find('img').length) return '';
    return '<div style="font-family:' + esc(p.compose_font || 'Arial, Helvetica, sans-serif') + ';font-size:' + esc(p.compose_size || '14px') + '">' + html + '</div>';
  };

  Compose.prototype.payload = function () {
    var s = this.state;
    return {
      from: this.$el.find('[name=from]').val() || '',
      to: this.rcpt.to.value(), cc: this.rcpt.cc.value(), bcc: this.rcpt.bcc.value(),
      subject: this.$el.find('[name=subject]').val(), html: this.bodyHtml(), mode: s.mode, ref_id: s.ref_id,
      ref_parts: s.ref_attachments.map(function (a) { return a.part; }), attachments: s.attachments.filter(function (a) { return a.token; }).map(function (a) { return a.token; }),
      draft_id: s.draft_id, priority: this.$el.find('[data-opt=priority]').is(':checked') ? 1 : 3,
      receipt: this.$el.find('[data-opt=receipt]').is(':checked') ? 1 : 0, attach_original: s.attach_original ? 1 : 0
    };
  };

  Compose.prototype.touch = function () {
    var self = this;
    this.state.dirty = true;
    this.updateTitle();
    clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(function () { self.saveDraft(true); }, 3000);
  };

  Compose.prototype.updateTitle = function () {
    var s = this.$el.find('[name=subject]').val();
    this.$el.find('.title').text(s || ({ reply: t('reply'), reply_all: t('reply_all'), forward: t('forward') }[this.state.mode] || t('new_message')));
  };

  Compose.prototype.hasContent = function () {
    return this.rcpt.to.list.length || this.rcpt.cc.list.length || this.$el.find('[name=subject]').val() || !this.editor.isEmpty() || this.state.attachments.length;
  };

  Compose.prototype.saveDraft = function (auto) {
    var self = this;
    if (this.state.sending || (!this.state.dirty && auto)) return $.Deferred().resolve().promise();
    if (!this.hasContent()) return $.Deferred().resolve().promise();
    if (this.state.uploading) { this.touch(); return $.Deferred().resolve().promise(); }
    this.$el.find('[data-saved]').text(t('saving'));
    this.state.dirty = false;
    return M4W.post('api/compose/draft', this.payload(), { silent: true, quiet: auto }).done(function (r) {
      self.state.draft_id = r.draft_id;
      self.$el.find('[data-saved]').text(t('draft_saved_at', { time: new Date().toLocaleTimeString(document.documentElement.lang, { hour: '2-digit', minute: '2-digit' }) }));
      if (M4W.state.byRole && M4W.state.folder === (M4W.state.byRole.drafts || {}).id) M4W.reloadList();
    }).fail(function () { self.state.dirty = true; self.$el.find('[data-saved]').text(''); });
  };

  Compose.prototype.showField = function (f) {
    this.$el.find('[data-f=' + f + ']').removeClass('d-none');
    this.$el.find('[data-c=' + f + ']').addClass('d-none');
  };

  Compose.prototype.renderAtts = function () {
    var self = this, $a = this.$el.find('[data-atts]').empty();
    this.state.ref_attachments.forEach(function (a, i) {
      $a.append('<span class="m4w-compose-att"><i class="bi bi-' + M4W.fileIcon(a.name, a.mime)[1] + '"></i><span class="name">' + esc(a.name) + '</span><span class="size">' + esc(M4W.bytes(a.size)) + '</span><button type="button" data-rm-ref="' + i + '" aria-label="' + esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button></span>');
    });
    if (this.state.attach_original) $a.append('<span class="m4w-compose-att"><i class="bi bi-envelope"></i><span class="name">' + esc(t('original_message')) + '.eml</span><button type="button" data-rm-orig aria-label="' + esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button></span>');
    this.state.attachments.forEach(function (a, i) {
      $a.append('<span class="m4w-compose-att" data-att="' + i + '"><i class="bi bi-' + M4W.fileIcon(a.name, a.mime)[1] + '"></i><span class="name">' + esc(a.name) + '</span><span class="size">' + (a.token ? esc(M4W.bytes(a.size)) : '<span class="spinner-border spinner-border-sm" style="width:.7rem;height:.7rem"></span>') + '</span>'
        + '<button type="button" data-rm-att="' + i + '" aria-label="' + esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button><span class="bar" style="width:' + (a.progress || 0) + '%"></span></span>');
    });
  };

  Compose.prototype.upload = function (file) {
    var self = this, max = ((M4W.state.settings || {}).max_attach_mb || 25) * 1048576;
    var d = $.Deferred();
    if (file.size > max) { M4W.toast(t('file_too_big', { name: file.name, mb: max / 1048576 }), 'danger'); return d.reject().promise(); }
    var fd = new FormData(); fd.append('file', file);
    this.state.uploading = (this.state.uploading || 0) + 1;
    M4W.api('POST', 'api/upload', fd, {
      silent: true, xhr: function () {
        var x = new window.XMLHttpRequest();
        x.upload.addEventListener('progress', function (e) { if (e.lengthComputable) d.notify(Math.round(e.loaded / e.total * 100)); });
        return x;
      }
    }).done(function (r) { d.resolve(r.file); }).fail(function () { d.reject(); }).always(function () { self.state.uploading--; });
    return d.promise();
  };

  Compose.prototype.addFiles = function (files) {
    var self = this;
    Array.prototype.forEach.call(files, function (file) {
      var att = { name: file.name, size: file.size, mime: file.type, progress: 0 };
      self.state.attachments.push(att);
      self.renderAtts();
      self.upload(file).progress(function (p) { att.progress = p; self.$el.find('[data-att="' + self.state.attachments.indexOf(att) + '"] .bar').css('width', p + '%'); })
        .done(function (f) { att.token = f.token; att.size = f.size; att.mime = f.mime; att.progress = 100; self.renderAtts(); self.touch(); })
        .fail(function () { self.state.attachments.splice(self.state.attachments.indexOf(att), 1); self.renderAtts(); });
    });
  };

  Compose.prototype.uploadInline = function (file) {
    return this.upload(file).then(function (f) { return f.url; });
  };

  Compose.prototype.bind = function () {
    var self = this, $el = this.$el;
    $el.on('click', '[data-c]', function (e) {
      var c = $(this).data('c');
      if (c === 'toggle-min') { if ($(e.target).closest('button').length) return; $el.toggleClass('minimized'); return; }
      e.stopPropagation();
      if (c === 'min') $el.toggleClass('minimized').removeClass('maximized'), $('.m4w-compose-backdrop').remove();
      if (c === 'max') self.toggleMax();
      if (c === 'close') self.close();
      if (c === 'cc' || c === 'bcc') { self.showField(c); self.rcpt[c].$input.trigger('focus'); }
      if (c === 'send') self.send();
      if (c === 'attach') $el.find('[data-file]').trigger('click');
      if (c === 'image') self.editor.exec('image');
      if (c === 'link') self.editor.exec('link');
      if (c === 'format') { $el.find('[data-toolbar-slot]').toggleClass('d-none'); $(this).toggleClass('active'); }
      if (c === 'discard') self.discard();
    });
    $el.find('[data-file]').on('change', function () { self.addFiles(this.files); this.value = ''; });
    $el.find('[name=subject]').on('input', function () { self.touch(); }).on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); self.editor.focus(true); } });
    $el.find('[name=from]').on('change', function () { self.touch(); });
    $el.on('click', '[data-rm-att]', function () { self.state.attachments.splice(+$(this).data('rm-att'), 1); self.renderAtts(); self.touch(); });
    $el.on('click', '[data-rm-ref]', function () { self.state.ref_attachments.splice(+$(this).data('rm-ref'), 1); self.renderAtts(); self.touch(); });
    $el.on('click', '[data-rm-orig]', function () { self.state.attach_original = false; self.renderAtts(); self.touch(); });
    var dragDepth = 0;
    $el.on('dragenter', function (e) { if (hasFiles(e)) { dragDepth++; $el.addClass('dragover'); } })
      .on('dragleave', function () { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) $el.removeClass('dragover'); })
      .on('dragover', function (e) { if (hasFiles(e)) e.preventDefault(); })
      .on('drop', function (e) {
        if (!hasFiles(e)) return;
        e.preventDefault(); dragDepth = 0; $el.removeClass('dragover');
        self.addFiles(e.originalEvent.dataTransfer.files);
      });
    $el.on('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); self.send(); }
      if (e.key === 'Escape' && !$(e.target).closest('.dropdown-menu').length) { e.preventDefault(); self.close(); }
    });
    function hasFiles(e) { var dt = e.originalEvent.dataTransfer; return dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1; }
  };

  Compose.prototype.toggleMax = function () {
    var $el = this.$el, self = this;
    $el.toggleClass('maximized').removeClass('minimized');
    $('.m4w-compose-backdrop').remove();
    if ($el.hasClass('maximized')) $('<div class="m4w-compose-backdrop"></div>').insertBefore($el).on('click', function () { self.toggleMax(); });
    $el.find('[data-c=max] .bi').toggleClass('bi-arrows-angle-expand bi-arrows-angle-contract');
  };

  Compose.prototype.close = function () {
    var self = this;
    clearTimeout(this.saveTimer);
    if (this.state.dirty && this.hasContent()) {
      this.saveDraft(false).done(function () { M4W.toast(t('draft_saved'), 'success'); });
    }
    self.destroy();
  };

  Compose.prototype.discard = function () {
    var self = this, id = this.state.draft_id;
    clearTimeout(this.saveTimer);
    this.state.sending = true;
    this.destroy();
    if (id) M4W.action('purge', [id], { silent: true }).done(function () { M4W.toast(t('draft_discarded'), 'info'); });
    else M4W.toast(t('draft_discarded'), 'info');
  };

  Compose.prototype.destroy = function () {
    clearTimeout(this.saveTimer);
    $('.m4w-compose-backdrop').remove();
    this.$el.remove();
    if (C === this) C = null;
  };

  Compose.prototype.validate = function () {
    var d = $.Deferred(), self = this;
    var payload = this.payload();
    var bad = [].concat(this.rcpt.to.invalid(), this.rcpt.cc.invalid(), this.rcpt.bcc.invalid());
    if (bad.length) { M4W.toast(t('invalid_address', { email: bad[0].email }), 'danger'); return d.reject().promise(); }
    if (!payload.to && !payload.cc && !payload.bcc) { M4W.toast(t('need_recipient'), 'warning'); this.rcpt.to.$input.trigger('focus'); return d.reject().promise(); }
    if (this.state.uploading) { M4W.toast(t('wait_uploads'), 'warning'); return d.reject().promise(); }
    var text = $('<div>').html(payload.html).text().toLowerCase().split(/-{5,}|\n>/)[0];
    var mentionsAtt = /(pi[eè]ce[s]? jointe|ci-joint|en pj|attach(ed|ment))/i.test(text);
    var hasAtt = this.state.attachments.length || this.state.ref_attachments.length || this.state.attach_original;
    var checks = [];
    if (!String(payload.subject).trim()) checks.push([t('no_subject_title'), t('no_subject_text'), t('send_anyway')]);
    if (mentionsAtt && !hasAtt) checks.push([t('forgot_attachment_title'), t('forgot_attachment_text'), t('send_anyway')]);
    (function next() {
      var c = checks.shift();
      if (!c) { d.resolve(payload); return; }
      M4W.confirm(c[0], c[1], c[2]).done(next).fail(function () { d.reject(); });
    })();
    return d.promise();
  };

  Compose.prototype.send = function () {
    var self = this;
    if (this.state.sending) return;
    this.validate().done(function (payload) {
      self.state.sending = true;
      clearTimeout(self.saveTimer);
      var delay = (M4W.state.settings || {}).undo_send;
      if ((M4W.state.prefs || {}).undo_send === 0) delay = 0;
      self.$el.addClass('d-none');
      $('.m4w-compose-backdrop').remove();
      var cancelled = false, toast;
      var doSend = function () {
        if (cancelled) return;
        if (toast) toast.close();
        var sending = M4W.toast(t('sending'), 'info', { duration: 60000 });
        M4W.post('api/compose/send', payload).done(function (r) {
          sending.close();
          self.destroy();
          M4W.toast(t('sent'), 'success', { action: { label: t('view_message'), fn: function () { var s = M4W.state.byRole.sent; if (s) M4W.go(s.id, r.id); } } });
          if (r.warnings && Object.keys(r.warnings).length) M4W.toast(t('partial_failure', { list: Object.keys(r.warnings).join(', ') }), 'warning', { duration: 10000 });
          M4W.reloadList();
        }).fail(function () {
          sending.close();
          self.state.sending = false;
          self.$el.removeClass('d-none');
        });
      };
      if (delay > 0) {
        var left = delay;
        toast = M4W.toast(t('sending_in', { n: left }), 'info', {
          duration: (delay + 1) * 1000,
          action: { label: t('undo'), fn: function () { cancelled = true; self.state.sending = false; self.$el.removeClass('d-none'); M4W.toast(t('send_cancelled'), 'info'); } }
        });
        var iv = setInterval(function () {
          left--;
          if (cancelled) { clearInterval(iv); return; }
          if (left <= 0) { clearInterval(iv); doSend(); return; }
          toast.el.find('.flex-grow-1').text(t('sending_in', { n: left }));
        }, 1000);
      } else {
        doSend();
      }
    });
  };

  M4W.Compose = {
    open: function (opts) {
      opts = opts || { mode: 'new' };
      if (C) {
        var prev = C;
        if (prev.hasContent() && prev.state.dirty) prev.saveDraft(false).done(function () { M4W.toast(t('draft_saved'), 'success'); });
        prev.destroy();
      }
      C = new Compose(opts);
      return C;
    },
    current: function () { return C; }
  };

  $(window).on('beforeunload', function () {
    if (C && C.state.dirty && C.hasContent() && !C.state.sending) return t('leave_unsaved');
  });
})(jQuery, window, document);
