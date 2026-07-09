<?php
/**
 * UX Customizer - Computer Dashboard tab template
 *
 * Server-side include (NOT browser-fetched), rendered by
 * ComputerDashboard::displayTabContentForItem(). Expects $data (see gatherData)
 * and $item (the Computer). All output is escaped; everything is wrapped in
 * .uxc-ci-detail so the dashboard CSS (public/css/dashboard.css) is scoped.
 *
 * @var array     $data
 * @var \Computer $item
 *
 * @license GPL-3.0-or-later
 */

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * One security status card. Prefers an explicit 4-state $c['level']
 * (ok/warn/bad/unknown); falls back to the ok tri-state used by native cards
 * (ok===true → good, false → bad, null → unknown).
 */
$card = static function (array $c) use ($h): string {
    $state = $c['level'] ?? ($c['ok'] === true ? 'ok' : ($c['ok'] === false ? 'bad' : 'unknown'));
    return '<div class="uxc-card uxc-status-card uxc-' . $state . '">'
        . '<div class="uxc-status-title"><span class="uxc-dot"></span>' . $h($c['label']) . '</div>'
        . '<div class="uxc-status-detail">' . $h($c['detail']) . '</div>'
        . '</div>';
};

$fmtDate = static fn($d) => $d ? $h(substr((string) $d, 0, 16)) : '—';
$num     = static fn($v) => $v === null ? '—' : (int) $v;
?>
<div class="uxc-ci-detail">

  <!-- Top bar -->
  <div class="uxc-topbar">
    <div class="uxc-topbar-main">
      <span class="uxc-ci-name"><?= $h($data['name']) ?></span>
      <span class="uxc-ci-sub"><?= $h($data['type_label']) ?> · <?= __('Updated', 'impact360') ?> <?= $fmtDate($data['updated']) ?></span>
    </div>
    <div class="uxc-topbar-badges">
      <span class="uxc-badge uxc-badge-status"><?= $h($data['status']) ?></span>
      <span class="uxc-badge"><i class="ti ti-map-pin"></i> <?= $h($data['location']) ?></span>
      <span class="uxc-badge"><i class="ti ti-user"></i> <?= $h($data['owner']) ?></span>
      <a class="uxc-btn" href="<?= $h($data['edit_url']) ?>"><i class="ti ti-edit"></i> <?= __('Edit') ?></a>
    </div>
  </div>

  <!-- Security status cards -->
  <div class="uxc-grid <?= !empty($data['security']) ? 'uxc-grid-4' : 'uxc-grid-3' ?>">
    <?= $card($data['connectivity']) ?>
    <?= $card($data['antivirus']) ?>
    <?= $card($data['health']) ?>
    <?php if (!empty($data['security'])): ?>
    <?= $card($data['security']) ?>
    <?php endif; ?>
  </div>

  <!-- Consolidated System info card (Software + Hardware + Lifecycle + Details) -->
  <?php $osm = \GlpiPlugin\Impact360\ComputerDashboard::osIcon((string) $data['software']['os']); ?>
  <div class="uxc-card uxc-sysinfo">
    <div class="uxc-card-title"><i class="ti ti-device-desktop me-1"></i><?= __('System info', 'impact360') ?></div>

    <div class="uxc-metrics">
      <div class="uxc-metric"><span class="uxc-metric-n"><?= (int) $data['software']['installed'] ?></span><span class="uxc-metric-l"><?= __('Installed', 'impact360') ?></span></div>
      <div class="uxc-metric"><span class="uxc-metric-n uxc-warn"><?= $num($data['software']['unlicensed']) ?></span><span class="uxc-metric-l"><?= __('Unlicensed', 'impact360') ?></span></div>
      <div class="uxc-metric"><span class="uxc-metric-n"><?= $num($data['software']['uptime']) ?></span><span class="uxc-metric-l"><?= __('Uptime', 'impact360') ?></span></div>
    </div>

    <div class="uxc-grid uxc-grid-3 uxc-sysinfo-grid">

      <div class="uxc-sysinfo-col">
        <div class="uxc-subhead"><i class="ti ti-apps me-1"></i><?= __('Software', 'impact360') ?></div>
        <dl class="uxc-kv">
          <dt><?= __('OS', 'impact360') ?></dt>
          <dd>
            <span class="uxc-os <?= $h($osm['tone']) ?>"><i class="<?= $h($osm['icon']) ?> me-1" aria-hidden="true"></i><?= $h($data['software']['os']) ?></span>
          </dd>
          <dt><?= __('Build', 'impact360') ?></dt><dd><?= $h($data['software']['build']) ?></dd>
          <dt><?= __('OS install date', 'impact360') ?></dt><dd><?= $fmtDate($data['software']['install_date']) ?></dd>
        </dl>
      </div>

      <div class="uxc-sysinfo-col">
        <div class="uxc-subhead"><i class="ti ti-cpu me-1"></i><?= __('Hardware', 'impact360') ?></div>
        <dl class="uxc-kv">
          <dt><?= __('Model') ?></dt><dd><?= $h($data['hardware']['model']) ?></dd>
          <dt><?= __('Processor') ?></dt><dd><?= $h($data['hardware']['cpu']) ?></dd>
          <dt><?= __('Memory') ?></dt><dd><?= $h($data['hardware']['ram']) ?></dd>
          <dt><?= __('Hard drive') ?></dt><dd><?= $h($data['hardware']['disk']) ?></dd>
          <?php if (!empty($data['hardware']['serial'])): ?>
            <dt><?= __('Serial') ?></dt><dd><?= $h($data['hardware']['serial']) ?></dd>
          <?php endif; ?>
          <?php if (!empty($data['hardware']['last_inventory'])): ?>
            <dt><?= __('Last inventory', 'impact360') ?></dt><dd><?= $fmtDate($data['hardware']['last_inventory']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>

      <div class="uxc-sysinfo-col">
        <div class="uxc-subhead"><i class="ti ti-recycle me-1"></i><?= __('Lifecycle', 'impact360') ?></div>
        <dl class="uxc-kv">
          <dt><?= __('Purchase date') ?></dt><dd><?= $fmtDate($data['lifecycle']['buy_date']) ?></dd>
          <dt><?= __('Warranty') ?></dt><dd><?php
            $wm = $data['lifecycle']['warranty_months'];
            if ($wm === -1)                                     { echo $h(__('Lifetime', 'impact360')); }
            elseif (!empty($data['lifecycle']['warranty_end'])) { echo $fmtDate($data['lifecycle']['warranty_end']); }
            elseif (!empty($wm))                                { echo (int) $wm . ' ' . __('months'); }
            else                                                { echo '—'; }
          ?></dd>
          <dt><?= __('Retention', 'impact360') ?></dt><dd><?= (int) $data['lifecycle']['retention_years'] ?> <?= __('years') ?></dd>
          <dt><?= __('Retirement', 'impact360') ?></dt><dd><?= $fmtDate($data['lifecycle']['retire_date']) ?></dd>
        </dl>
        <?php if (!empty($data['lifecycle']['remaining'])): ?>
          <div class="uxc-life-status <?= $data['lifecycle']['overdue'] ? 'uxc-life-overdue' : 'uxc-life-ok' ?>"><?= $h($data['lifecycle']['remaining']) ?></div>
        <?php endif; ?>
      </div>

    </div>

    <?php
    // Show only volumes with actual usage data; tiny or unknown volumes were
    // creating big empty rows in the panel. Keeps the section compact.
    $volsShown = array_values(array_filter($data['volumes'] ?? [], static function (array $v): bool {
        return $v['used_pct'] !== null && (($v['total_gb'] ?? 0) >= 1);
    }));
    ?>
    <?php if (!empty($volsShown)): ?>
      <div class="uxc-subhead uxc-subhead-row"><i class="ti ti-database me-1"></i><?= __('Volumes', 'impact360') ?></div>
      <div class="uxc-volumes">
        <?php foreach ($volsShown as $v): ?>
          <div class="uxc-vol">
            <div class="uxc-vol-top">
              <span class="uxc-vol-mount"><?= $h($v['mount']) ?><?= $v['total_gb'] !== null ? ' · ' . $h($v['total_gb']) . ' GB' : '' ?></span>
              <span class="uxc-vol-pct"><?= (int) $v['used_pct'] . ' %' ?></span>
            </div>
            <?php $p = (int) $v['used_pct']; $bar = $p >= 90 ? 'uxc-bar-bad' : ($p >= 75 ? 'uxc-bar-warn' : 'uxc-bar-ok'); ?>
            <div class="uxc-bar"><div class="uxc-bar-fill <?= $bar ?>" style="width: <?= $p ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>

  <!-- Tickets + Contracts side-by-side -->
  <div class="uxc-grid uxc-grid-2">

    <div class="uxc-card">
      <div class="uxc-card-head">
        <div class="uxc-card-title"><i class="ti ti-ticket me-1"></i><?= __('Tickets', 'impact360') ?></div>
        <a class="uxc-btn uxc-btn-sm" href="<?= $h($data['new_ticket_url']) ?>"><i class="ti ti-plus"></i> <?= __('New ticket', 'impact360') ?></a>
      </div>
      <div class="uxc-metrics">
        <div class="uxc-metric"><span class="uxc-metric-n"><?= $num($data['tickets']['open']) ?></span><span class="uxc-metric-l"><?= __('Open') ?></span></div>
        <div class="uxc-metric"><span class="uxc-metric-n"><?= (int) $data['tickets']['linked'] ?></span><span class="uxc-metric-l"><?= __('Linked', 'impact360') ?></span></div>
        <div class="uxc-metric"><span class="uxc-metric-n"><?= $num($data['tickets']['pending']) ?></span><span class="uxc-metric-l"><?= __('Pending') ?></span></div>
      </div>
      <a class="uxc-link" href="<?= $h($data['tickets_url']) ?>"><?= __('View all tickets', 'impact360') ?> →</a>
    </div>

    <div class="uxc-card">
      <div class="uxc-card-title"><i class="ti ti-file-text me-1"></i><?= __('Contracts', 'impact360') ?></div>
      <dl class="uxc-kv">
        <dt><?= __('Assigned', 'impact360') ?></dt><dd><?= (int) $data['contracts']['assigned'] ?></dd>
        <dt><?= __('Type') ?></dt><dd><?= $h($data['contracts']['type']) ?></dd>
        <dt><?= __('Value') ?></dt><dd><?= $data['contracts']['value'] === null ? '—' : $h($data['contracts']['value']) ?></dd>
      </dl>
    </div>

  </div>

  <!-- Recent activity timeline -->
  <?php if (!empty($data['activity'])): ?>
  <div class="uxc-card uxc-activity">
    <div class="uxc-card-title"><i class="ti ti-history me-1"></i><?= __('Recent activity', 'impact360') ?></div>
    <ul class="uxc-timeline">
      <?php foreach ($data['activity'] as $a): ?>
        <li>
          <span class="uxc-tl-date"><?= $fmtDate($a['date']) ?></span>
          <?php if ($a['who'] !== ''): ?><span class="uxc-tl-who"><?= $h($a['who']) ?></span><?php endif; ?>
          <span class="uxc-tl-text"><?= $h(mb_strimwidth((string) $a['text'], 0, 80, '…')) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
