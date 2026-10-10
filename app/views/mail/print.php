<?php
$to = json_decode((string) $m['to_list'], true) ?: [];
$cc = json_decode((string) $m['cc_list'], true) ?: [];
$fmt = static fn(array $l) => e(implode(', ', array_map(static fn($a) => M4W\Mail\Address::format($a['email'], $a['name']), $l)));
?>
<div class="container py-4" style="max-width:860px;background:#fff;color:#111">
  <div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <?php include __DIR__ . '/../partials/brand.php'; ?>
    <button class="btn btn-primary" data-print><i class="bi bi-printer me-1"></i><?= te('mail.print') ?></button>
  </div>
  <h1 class="h4 fw-semibold mb-3"><?= e($m['subject'] ?: t('mail.no_subject')) ?></h1>
  <table class="table table-sm small mb-4" style="--bs-table-color:#111">
    <tr><th style="width:90px"><?= te('mail.from') ?></th><td><?= e(M4W\Mail\Address::format($m['from_email'], $m['from_name'])) ?></td></tr>
    <tr><th><?= te('mail.to') ?></th><td><?= $fmt($to) ?></td></tr>
    <?php if ($cc): ?><tr><th>Cc</th><td><?= $fmt($cc) ?></td></tr><?php endif; ?>
    <tr><th><?= te('mail.date') ?></th><td><?= e(format_datetime((int) ($m['date_sent'] ?: $m['date_received']))) ?></td></tr>
  </table>
  <div class="m4w-print-body" style="font-size:14px;line-height:1.55"><?= $html ?></div>
</div>
<script src="<?= e(asset('js/print.js')) ?>"></script>
