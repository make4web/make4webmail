/*! Make4Web Mail — settings & admin pages */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W, t = M4W.t, esc = M4W.esc;

  $(function () {
    // ---------------------------------------------------------- generic
    $(document).on('change', '[data-check-all]', function () {
      var name = $(this).data('check-all');
      $(this).closest('form, table').find('input[name="' + name + '"]:not(:disabled)').prop('checked', this.checked).trigger('change');
    });
    $(document).on('change', 'input[name="ids[]"]', function () {
      var $f = $(this).closest('form'), n = $f.find('input[name="ids[]"]:checked').length;
      $f.find('[data-bulk-bar]').toggleClass('d-none', !n).toggleClass('d-flex', !!n);
      $f.find('[data-bulk-count]').text(t('selected_count', { n: n }));
    });
    $(document).on('click', '[data-confirm-bulk]', function (e) {
      var $b = $(this), $f = $b.closest('form');
      if ($f[0]._confirmed) return;
      e.preventDefault();
      M4W.confirm($b.data('confirm-bulk'), '', t('confirm')).done(function () { $f[0]._confirmed = true; $f[0].submit(); });
    });
    $(document).on('input', '[data-live-filter]', function () {
      var q = this.value.toLowerCase();
      $($(this).data('live-filter')).find('tbody tr').each(function () { $(this).toggle(($(this).data('filter-text') || '').indexOf(q) !== -1); });
    });
    $('[data-live-filter]').closest('form').on('submit', function (e) { e.preventDefault(); });
    $(document).on('change', '[data-autosubmit]', function () { this.form.submit(); });
    $(document).on('click', '[data-submit-form]', function () { $($(this).data('submit-form')).trigger('submit'); });
    $(document).on('click', '[data-copy]', function () {
      var v = $(this).data('copy'), $b = $(this);
      (navigator.clipboard ? navigator.clipboard.writeText(v) : $.Deferred().reject().promise()).then(function () { M4W.toast(t('copied'), 'success'); }, function () {
        var $ta = $('<textarea>').val(v).appendTo('body').trigger('select'); document.execCommand('copy'); $ta.remove(); M4W.toast(t('copied'), 'success');
      });
      $b.blur();
    });
    $(document).on('focus click', '[data-select-all]', function () { this.select(); });

    // QR codes (2FA)
    $('[data-qr]').each(function () {
      if (typeof window.qrcode !== 'function') return;
      var qr = window.qrcode(0, 'M');
      qr.addData($(this).data('qr'));
      qr.make();
      $(this).html(qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true }));
    });

    // Modals filled from data-* JSON
    $('#contact-modal').on('show.bs.modal', function (e) {
      var data = $(e.relatedTarget).data('contact') || {}, $f = $(this).find('form');
      $f[0].reset();
      ['id', 'name', 'email', 'phone', 'company', 'notes'].forEach(function (k) { $f.find('[name=' + k + ']').val(data[k] || ''); });
      $f.find('[name=is_favorite]').prop('checked', !!data.is_favorite);
      $f.find('[data-delete-contact]').toggleClass('d-none', !data.id).off('click').on('click', function () {
        M4W.confirm(t('confirm_delete_contact')).done(function () { $('#contact-delete-form').find('[name=id]').val(data.id).end()[0].submit(); });
      });
    });
    $('#acc-modal').on('show.bs.modal', function (e) {
      var d = $(e.relatedTarget).data('account') || {}, $f = $(this).find('form');
      $f[0].reset();
      ['id', 'label', 'host', 'port', 'security', 'username', 'remote_folder'].forEach(function (k) { if (d[k] !== undefined) $f.find('[name=' + k + ']').val(d[k]); });
      if (!d.id) { $f.find('[name=port]').val(993); $f.find('[name=remote_folder]').val('INBOX'); }
      ['enabled', 'apply_rules', 'delete_remote'].forEach(function (k) { if (d[k] !== undefined) $f.find('[name=' + k + ']').prop('checked', !!+d[k]); });
    });
    $('#acc-modal [name=security]').on('change', function () { $('#acc-modal [name=port]').val(this.value === 'ssl' ? 993 : 143); });

    // ----------------------------------------------------- user form
    $('[data-gen-password]').on('click', function () {
      var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', sym = '!@#$%&*?-+', pw = '';
      var rnd = new Uint32Array(16); window.crypto.getRandomValues(rnd);
      for (var i = 0; i < 14; i++) pw += chars[rnd[i] % chars.length];
      pw += sym[rnd[14] % sym.length] + (rnd[15] % 10);
      $('[data-pw]').val(pw).trigger('input');
    });
    var $local = $('[data-local-part]');
    if ($local.length) {
      var touched = false;
      $local.on('input', function () { touched = true; });
      $('[data-fn], [data-ln]').on('input', function () {
        if (touched) return;
        var norm = function (s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); };
        $local.val([norm($('[data-fn]').val()), norm($('[data-ln]').val())].filter(Boolean).join('.'));
      });
    }

    // ---------------------------------------------------- vacation
    var $vac = $('form[data-vacation]');
    if ($vac.length) {
      var syncVac = function () { $vac.find('[data-vac-body]').css('opacity', $('#vac-en').is(':checked') ? 1 : .55); };
      $('#vac-en').on('change', syncVac); syncVac();
      var tpls = {}; try { tpls = JSON.parse($('#vac-templates').text()); } catch (e) {}
      $('[data-vac-template]').on('click', function () {
        var html = tpls[$(this).data('vac-template')];
        var $ed = $vac.find('.m4w-editor');
        if ($ed.text().trim() && !window.confirm(t('replace_message'))) return;
        $ed.html(html).trigger('input');
        $vac.find('textarea[name=body_html]').val(html);
      });
    }

    // -------------------------------------------------- rules builder
    var $rules = $('#rules-list');
    if ($rules.length) initRules($rules);

    // --------------------------------------------- signature template
    var $sig = $('#sig-html');
    if ($sig.length) {
      var preview = M4W.debounce(function () {
        M4W.post('admin/signatures/preview', { html: $sig.val(), user_id: $('#sig-preview-user').val() }, { silent: true, quiet: true }).done(function (r) { $('#sig-preview').html(r.html); });
      }, 300);
      $sig.on('input', preview);
      $('#sig-preview-user').on('change', preview);
      preview();
      $('[data-insert]').on('click', function () {
        var el = $sig[0], v = $(this).data('insert'), s = el.selectionStart, e2 = el.selectionEnd;
        el.value = el.value.slice(0, s) + v + el.value.slice(e2);
        el.selectionStart = el.selectionEnd = s + v.length;
        el.focus(); preview();
      });
      $('[data-sig-reset]').on('click', function () {
        M4W.confirm(t('sig_reset_confirm')).done(function () { $sig.val(JSON.parse($('#sig-default').text())); preview(); });
      });
      $sig.on('keydown', function (e) {
        if (e.key === 'Tab') { e.preventDefault(); var s = this.selectionStart; this.value = this.value.slice(0, s) + '  ' + this.value.slice(this.selectionEnd); this.selectionStart = this.selectionEnd = s + 2; }
      });
    }

    // ----------------------------------------------------- branding
    var $bf = $('#brand-form');
    if ($bf.length) initBranding($bf);

    // ---------------------------------------------------- test mail
    $('form[data-test-mail]').on('submit', function (e) {
      e.preventDefault();
      var $f = $(this), $r = $f.find('[data-test-result]').html('<span class="spinner-border spinner-border-sm me-2"></span>' + esc(t('sending')));
      M4W.post('admin/mail/test', { to: $f.find('[name=to]').val() }, { quiet: true })
        .done(function (r) { $r.html('<div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill"></i><div>' + esc(r.message) + '</div></div>'); })
        .fail(function (x) { $r.html('<div class="alert alert-danger mb-0"><i class="bi bi-x-octagon-fill"></i><div class="m4w-code" style="word-break:break-word">' + esc((x.responseJSON && x.responseJSON.error) || t('error_network')) + '</div></div>'); });
    });
  });

  // =================================================================== rules
  function initRules($root) {
    var rules = $root.data('rules') || [], folders = $root.data('folders') || [], prefill = $root.data('prefill') || {};
    var FIELDS = ['from', 'to', 'cc', 'recipients', 'subject', 'body', 'header', 'size', 'has_attachment', 'priority'];
    var TEXT_OPS = ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with'];
    var ACTIONS = ['move', 'copy', 'mark_read', 'flag', 'trash', 'redirect', 'reply', 'discard', 'stop'];
    var $modal = $('#rule-modal'), $form = $('#rule-form');

    function folderOptions(sel) {
      return folders.filter(function (f) { return f.role !== 'drafts' && f.role !== 'sent'; }).map(function (f) { return '<option value="' + f.id + '"' + (+sel === f.id ? ' selected' : '') + '>' + esc(f.name) + '</option>'; }).join('');
    }
    function folderName(id) { var f = folders.filter(function (x) { return x.id === +id; })[0]; return f ? f.name : '?'; }

    function summary(r) {
      var c = (r.conditions || []).map(function (x) {
        if (x.field === 'has_attachment') return '<b>' + esc(t(x.op === 'is_false' ? 'rf_no_attachment' : 'rf_has_attachment')) + '</b>';
        if (x.field === 'priority') return '<b>' + esc(t('rf_priority_high')) + '</b>';
        if (x.field === 'size') return esc(t('rf_size')) + ' ' + esc(t('ro_' + x.op)) + ' <b>' + esc(x.value) + ' Ko</b>';
        return esc(x.field === 'header' ? x.header : t('rf_' + x.field)) + ' ' + esc(t('ro_' + x.op)) + ' <b>« ' + esc(x.value) + ' »</b>';
      }).join(r.match_type === 'any' ? ' ' + esc(t('or')) + ' ' : ' ' + esc(t('and')) + ' ');
      var a = (r.actions || []).map(function (x) {
        if (x.type === 'move' || x.type === 'copy') return esc(t('ra_' + x.type)) + ' <b>' + esc(folderName(x.folder)) + '</b>';
        if (x.type === 'redirect') return esc(t('ra_redirect')) + ' <b>' + esc(x.to) + '</b>';
        return esc(t('ra_' + x.type));
      }).join(', ');
      return (c ? esc(t('if')) + ' ' + c : esc(t('all_messages'))) + ' → ' + a;
    }

    function render() {
      if (!rules.length) {
        $root.html('<div class="m4w-empty py-5"><div class="m4w-empty-icon"><i class="bi bi-funnel"></i></div><h3>' + esc(t('no_rules')) + '</h3><p class="small">' + esc(t('no_rules_hint')) + '</p></div>');
        return;
      }
      $root.html(rules.map(function (r) {
        return '<div class="m4w-rule' + (+r.enabled ? '' : ' disabled') + '" data-id="' + r.id + '" draggable="true">'
          + '<i class="bi bi-grip-vertical handle" title="' + esc(t('drag_reorder')) + '"></i>'
          + '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" data-toggle-rule ' + (+r.enabled ? 'checked' : '') + ' aria-label="' + esc(t('enabled')) + '"></div>'
          + '<div class="flex-grow-1 min-w-0"><div class="fw-semibold">' + esc(r.name) + (+r.stop_processing ? ' <span class="badge badge-soft-secondary ms-1">' + esc(t('stop_short')) + '</span>' : '') + '</div><div class="m4w-rule-summary text-truncate">' + summary(r) + '</div></div>'
          + '<span class="small text-muted text-nowrap d-none d-md-inline" title="' + esc(t('hits')) + '"><i class="bi bi-lightning"></i> ' + (+r.hits || 0) + '</span>'
          + '<div class="dropdown"><button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">'
          + '<button class="dropdown-item" data-edit-rule><i class="bi bi-pencil"></i>' + esc(t('edit')) + '</button>'
          + '<button class="dropdown-item" data-run-rule><i class="bi bi-play-circle"></i>' + esc(t('run_now')) + '</button>'
          + '<button class="dropdown-item" data-dup-rule><i class="bi bi-copy"></i>' + esc(t('duplicate')) + '</button>'
          + '<div class="dropdown-divider"></div><button class="dropdown-item text-danger" data-del-rule><i class="bi bi-trash3 text-danger"></i>' + esc(t('delete')) + '</button></div></div></div>';
      }).join(''));
    }

    function condRow(c) {
      c = c || { field: 'from', op: 'contains', value: '' };
      var $r = $('<div class="m4w-cond-row"></div>');
      $r.append('<select class="form-select form-select-sm" data-k="field">' + FIELDS.map(function (f) { return '<option value="' + f + '"' + (c.field === f ? ' selected' : '') + '>' + esc(t('rf_' + f)) + '</option>'; }).join('') + '</select>');
      $r.append('<select class="form-select form-select-sm" data-k="op"></select>');
      $r.append('<div class="d-flex gap-1"><input class="form-control form-control-sm" data-k="header" placeholder="X-Header" style="max-width:120px"><input class="form-control form-control-sm" data-k="value"></div>');
      $r.append('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-rm aria-label="' + esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button>');
      var sync = function () {
        var f = $r.find('[data-k=field]').val(), ops;
        if (f === 'size') ops = ['gt', 'lt'];
        else if (f === 'has_attachment' || f === 'priority') ops = ['is_true', 'is_false'];
        else ops = TEXT_OPS;
        var cur = $r.find('[data-k=op]').val() || c.op;
        $r.find('[data-k=op]').html(ops.map(function (o) { return '<option value="' + o + '"' + (cur === o ? ' selected' : '') + '>' + esc(t('ro_' + o)) + '</option>'; }).join(''));
        $r.find('[data-k=value]').toggle(ops === TEXT_OPS || f === 'size').attr('placeholder', f === 'size' ? 'Ko' : t('value')).attr('type', f === 'size' ? 'number' : 'text');
        $r.find('[data-k=header]').toggle(f === 'header');
      };
      $r.find('[data-k=field]').on('change', sync);
      $r.find('[data-k=value]').val(c.value || '');
      $r.find('[data-k=header]').val(c.header || '');
      sync();
      $r.find('[data-k=op]').val(c.op);
      return $r;
    }

    function actRow(a) {
      a = a || { type: 'move' };
      var $r = $('<div class="m4w-act-row"></div>');
      $r.append('<select class="form-select form-select-sm" data-k="type">' + ACTIONS.map(function (x) { return '<option value="' + x + '"' + (a.type === x ? ' selected' : '') + '>' + esc(t('ra_' + x)) + '</option>'; }).join('') + '</select>');
      var $p = $('<div></div>').appendTo($r);
      $r.append('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-rm aria-label="' + esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button>');
      var sync = function () {
        var ty = $r.find('[data-k=type]').val();
        if (ty === 'move' || ty === 'copy') $p.html('<select class="form-select form-select-sm" data-k="folder">' + folderOptions(a.folder) + '</select>');
        else if (ty === 'redirect') $p.html('<input type="email" class="form-control form-control-sm" data-k="to" placeholder="adresse@exemple.com">').find('input').val(a.to || '');
        else if (ty === 'reply') $p.html('<input class="form-control form-control-sm mb-1" data-k="subject" placeholder="' + esc(t('subject')) + '"><textarea class="form-control form-control-sm" rows="3" data-k="body" placeholder="' + esc(t('reply_text')) + '"></textarea>').find('[data-k=subject]').val(a.subject || '').end().find('[data-k=body]').val(a.body || '');
        else if (ty === 'discard') $p.html('<div class="small text-danger pt-1"><i class="bi bi-exclamation-triangle"></i> ' + esc(t('discard_warning')) + '</div>');
        else $p.html('');
      };
      $r.find('[data-k=type]').on('change', function () { a = { type: this.value }; sync(); });
      sync();
      return $r;
    }

    function open(rule) {
      rule = rule || { id: 0, name: '', enabled: 1, match_type: 'all', conditions: [{ field: 'from', op: 'contains', value: '' }], actions: [{ type: 'move' }], stop_processing: 0 };
      $form[0].reset();
      $form.data('id', rule.id || 0);
      $modal.find('[data-title]').text(rule.id ? t('edit_rule') : t('new_rule'));
      $form.find('[name=name]').val(rule.name);
      $form.find('[name=enabled]').prop('checked', !!+rule.enabled);
      $form.find('[name=match_type]').val(rule.match_type);
      $form.find('[name=stop_processing]').prop('checked', !!+rule.stop_processing);
      var $c = $form.find('[data-conds]').empty(); (rule.conditions.length ? rule.conditions : [null]).forEach(function (c) { $c.append(condRow(c)); });
      var $a = $form.find('[data-acts]').empty(); (rule.actions.length ? rule.actions : [null]).forEach(function (a) { $a.append(actRow(a)); });
      bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }

    function collect() {
      return {
        id: $form.data('id') || 0, name: $form.find('[name=name]').val(), enabled: $form.find('[name=enabled]').is(':checked') ? 1 : 0,
        match_type: $form.find('[name=match_type]').val(), stop_processing: $form.find('[name=stop_processing]').is(':checked') ? 1 : 0,
        apply_now: $form.find('[name=apply_now]').is(':checked') ? 1 : 0,
        conditions: $form.find('.m4w-cond-row').map(function () { var $r = $(this); return { field: $r.find('[data-k=field]').val(), op: $r.find('[data-k=op]').val(), value: $r.find('[data-k=value]').val(), header: $r.find('[data-k=header]').val() }; }).get(),
        actions: $form.find('.m4w-act-row').map(function () { var $r = $(this), o = { type: $r.find('[data-k=type]').val() }; $r.find('[data-k]').not('[data-k=type]').each(function () { o[$(this).data('k')] = $(this).val(); }); return o; }).get()
      };
    }

    function reload() { window.location.reload(); }

    $form.on('click', '[data-add-cond]', function () { $form.find('[data-conds]').append(condRow()); });
    $form.on('click', '[data-add-act]', function () { $form.find('[data-acts]').append(actRow({ type: 'mark_read' })); });
    $form.on('click', '[data-rm]', function () { $(this).parent().remove(); });
    $form.on('submit', function (e) {
      e.preventDefault();
      var rule = collect();
      M4W.post('settings/rules/save', { rule: JSON.stringify(rule) }).done(function (r) {
        bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
        M4W.toast(rule.apply_now ? t('rule_saved_applied', { n: r.applied }) : t('rule_saved'), 'success');
        setTimeout(reload, 600);
      });
    });
    $(document).on('click', '[data-rule-new]', function () { open(); });
    $root.on('click', '[data-edit-rule]', function () { var id = +$(this).closest('.m4w-rule').data('id'); open(rules.filter(function (r) { return +r.id === id; })[0]); });
    $root.on('click', '[data-dup-rule]', function () {
      var id = +$(this).closest('.m4w-rule').data('id'), r = $.extend(true, {}, rules.filter(function (x) { return +x.id === id; })[0]);
      r.id = 0; r.name += ' (' + t('copy_suffix') + ')'; open(r);
    });
    $root.on('click', '[data-del-rule]', function () {
      var id = +$(this).closest('.m4w-rule').data('id');
      M4W.confirm(t('confirm_delete_rule'), '', t('delete')).done(function () { M4W.post('settings/rules/delete', { id: id }).done(function () { rules = rules.filter(function (r) { return +r.id !== id; }); render(); M4W.toast(t('rule_deleted'), 'success'); }); });
    });
    $root.on('click', '[data-run-rule]', function () {
      var id = +$(this).closest('.m4w-rule').data('id');
      M4W.post('settings/rules/run', { id: id }).done(function (r) { M4W.toast(r.message, 'success'); });
    });
    $root.on('change', '[data-toggle-rule]', function () {
      var $r = $(this).closest('.m4w-rule'), id = +$r.data('id'), on = this.checked;
      $r.toggleClass('disabled', !on);
      M4W.post('settings/rules/toggle', { id: id, enabled: on ? 1 : 0 }, { silent: true });
    });
    // drag to reorder
    var dragging = null;
    $root.on('dragstart', '.m4w-rule', function (e) { dragging = this; e.originalEvent.dataTransfer.effectAllowed = 'move'; $(this).css('opacity', .5); })
      .on('dragend', '.m4w-rule', function () { $(this).css('opacity', ''); dragging = null; })
      .on('dragover', '.m4w-rule', function (e) {
        if (!dragging || dragging === this) return;
        e.preventDefault();
        var rect = this.getBoundingClientRect(), after = (e.originalEvent.clientY - rect.top) > rect.height / 2;
        if (after) $(this).after(dragging); else $(this).before(dragging);
      })
      .on('drop', function (e) {
        e.preventDefault();
        var ids = $root.find('.m4w-rule').map(function () { return $(this).data('id'); }).get();
        M4W.post('settings/rules/order', { ids: ids }, { silent: true });
      });

    render();
    if (prefill.from || prefill.subject) {
      var conds = [];
      if (prefill.from) conds.push({ field: 'from', op: 'contains', value: prefill.from });
      if (prefill.subject && !prefill.from) conds.push({ field: 'subject', op: 'contains', value: prefill.subject });
      open({ id: 0, name: prefill.from ? t('rule_from', { from: prefill.from }) : '', enabled: 1, match_type: 'all', conditions: conds, actions: [{ type: 'move' }], stop_processing: 0 });
      history.replaceState(null, '', window.location.pathname);
    }
  }

  // ================================================================ branding
  function initBranding($f) {
    var $pv = $('#brand-preview');
    function hexToRgb(h) { h = h.replace('#', ''); return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)]; }
    function shade(h, p) { var c = hexToRgb(h); return '#' + c.map(function (v) { v = Math.round(p < 0 ? v * (1 + p) : v + (255 - v) * p); return ('0' + Math.max(0, Math.min(255, v)).toString(16)).slice(-2); }).join(''); }
    function contrast(h) { var c = hexToRgb(h).map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return (.2126 * c[0] + .7152 * c[1] + .0722 * c[2]) > .45 ? '#0f172a' : '#ffffff'; }
    function apply() {
      var p = $f.find('[name=primary]').val(), a = $f.find('[name=accent]').val(), r = +$f.find('[name=radius]').val(), side = $f.find('[name=sidebar]').val();
      var rgb = hexToRgb(p).join(',');
      var el = $pv[0];
      el.style.setProperty('--m4w-primary', p); el.style.setProperty('--m4w-primary-rgb', rgb);
      el.style.setProperty('--m4w-primary-hover', shade(p, -.12)); el.style.setProperty('--m4w-primary-soft', 'rgba(' + rgb + ',.12)');
      el.style.setProperty('--m4w-on-primary', contrast(p)); el.style.setProperty('--m4w-accent', a);
      el.style.setProperty('--m4w-radius-sm', Math.max(0, r - 4) + 'px'); el.style.setProperty('--bs-border-radius', r + 'px');
      $pv.find('.btn').css('border-radius', Math.max(0, r - 4) + 'px');
      $pv.find('.pv-main, .pv-side').css('border-radius', (r + 2) + 'px');
      $pv.find('[data-pv-mark]').css({ background: 'linear-gradient(135deg,' + p + ',' + a + ')', borderRadius: Math.max(4, r - 2) + 'px' });
      $pv.find('[data-pv-avatar]').css('background', a);
      $pv.find('[data-pv-primary-text]').css('color', p);
      var sideBg = side === 'brand' ? p : (side === 'dark' ? '#0f172a' : 'transparent'), sideFg = side === 'brand' ? contrast(p) : (side === 'dark' ? '#e2e8f0' : 'var(--m4w-text-2)');
      $pv.find('[data-pv-side]').css({ background: sideBg, color: sideFg });
      $pv.find('[data-pv-active]').css({ background: side === 'light' ? 'rgba(' + rgb + ',.12)' : 'rgba(255,255,255,.18)', color: side === 'light' ? p : sideFg });
      $('[data-radius-val]').text(r + 'px');
      $pv.find('[data-pv-name]').text($f.find('[name=name]').val());
    }
    $f.on('input change', '[data-live]', apply);
    $f.on('input', '[data-hex-for]', function () {
      var v = this.value.trim(); if (/^#[0-9a-f]{6}$/i.test(v)) { $f.find('[name=' + $(this).data('hex-for') + ']').val(v.toLowerCase()); apply(); }
    });
    $f.on('input', 'input[type=color]', function () { $f.find('[data-hex-for=' + this.name + ']').val(this.value); });
    $f.on('click', '[data-preset]', function () { var k = $(this).data('preset'), c = $(this).data('color'); $f.find('[name=' + k + ']').val(c); $f.find('[data-hex-for=' + k + ']').val(c); apply(); });
    $f.on('change', '[data-preview-img]', function () {
      var k = $(this).data('preview-img'), file = this.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () { $f.find('[data-thumb=' + k + ']').html($('<img alt="">').attr('src', reader.result)); if (k === 'logo') $pv.find('[data-pv-mark]').replaceWith($('<img alt="" style="max-height:26px;max-width:110px">').attr('src', reader.result)); };
      reader.readAsDataURL(file);
    });
    apply();
  }
})(jQuery, window, document);
