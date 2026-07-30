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

  <!-- CVE Exposure: combined Nexpose + Defender CVE table (top 10, full list via Export) -->
  <?php if ($data['cves'] !== null):
    $cveSeverity = static function (string $sev) use ($h): string {
        $map = [
            'critical' => '#d63939', 'severe' => '#f59f00', 'high' => '#f59f00',
            'moderate' => '#f7c948', 'medium' => '#f7c948', 'low' => '#74b816',
        ];
        $color = $map[$sev] ?? '#6c757d';
        $label = $sev !== '' ? ucfirst($sev) : __('Unknown', 'impact360');
        return '<span class="uxc-pill" style="background:' . $color . ';color:#fff">' . $h($label) . '</span>';
    };
    $sourceLabels = ['nexpose' => __('Nexpose', 'impact360'), 'defender' => __('Defender', 'impact360')];
    $allCves      = $data['cves']['items'];
    $vulnCount    = count(array_filter($allCves, static fn($i) => $i['vulnerable']));
    $shownCves    = array_slice($allCves, 0, 10);
  ?>
  <div class="uxc-card">
    <div class="uxc-card-head uxc-cve-head">
      <div class="uxc-card-title"><i class="ti ti-bug me-1"></i><?= __('CVE Exposure', 'impact360') ?>
        <?php if (!empty($allCves)): ?>
          <span class="uxc-muted"><?= sprintf(__('(%1$d open · %2$d total)', 'impact360'), $vulnCount, count($allCves)) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($allCves)): ?>
        <a class="uxc-btn uxc-btn-sm" href="<?= $h($data['cve_export_url']) ?>"><i class="ti ti-file-spreadsheet"></i> <?= __('Export to Excel', 'impact360') ?></a>
      <?php endif; ?>
    </div>
    <?php if (empty($allCves)): ?>
      <div class="uxc-muted"><i class="ti ti-shield-check me-1"></i><?= __('No known CVEs.', 'impact360') ?></div>
    <?php else: ?>
      <div class="uxc-table-wrap">
        <table class="uxc-table">
          <thead><tr>
            <th><?= __('Severity', 'impact360') ?></th>
            <th><?= __('CVE', 'impact360') ?></th>
            <th><?= __('Title', 'impact360') ?></th>
            <th><?= __('CVSS', 'impact360') ?></th>
            <th><?= __('Exploit', 'impact360') ?></th>
            <th><?= __('Source', 'impact360') ?></th>
            <th><?= __('Status', 'impact360') ?></th>
            <th><?= __('Last seen', 'impact360') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($shownCves as $row): ?>
            <tr<?= !$row['vulnerable'] ? ' class="uxc-cve-remediated"' : '' ?>>
              <td><?= $cveSeverity($row['severity']) ?></td>
              <td><?= $h($row['cve']) ?></td>
              <td><?= $h(mb_strimwidth($row['title'], 0, 60, '…')) ?></td>
              <td><?= $row['cvss'] !== '' ? $h($row['cvss']) : '—' ?></td>
              <td><?= $row['exploitable'] ? '<span class="uxc-pill" style="background:#ae3ec9;color:#fff">' . __('Yes', 'impact360') . '</span>' : '—' ?></td>
              <td>
                <?php foreach ($sourceLabels as $key => $label): ?>
                  <?php if (isset($row['sources'][$key])): ?>
                    <?php $url = $row['sources'][$key]; ?>
                    <?= $url !== null
                        ? '<a class="uxc-tag" style="text-decoration:none;color:inherit" href="' . $h($url) . '">' . $h($label) . '</a>'
                        : '<span class="uxc-tag">' . $h($label) . '</span>' ?>
                  <?php endif; ?>
                <?php endforeach; ?>
              </td>
              <td><?= $row['vulnerable']
                    ? '<span class="uxc-pill" style="background:#d63939;color:#fff">' . __('Vulnerable', 'impact360') . '</span>'
                    : '<span class="uxc-pill" style="background:#2fb344;color:#fff">' . __('Remediated', 'impact360') . '</span>' ?></td>
              <td><?= $fmtDate($row['last_seen']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($allCves) > 10): ?>
        <div class="uxc-muted uxc-cve-more">
          <?= sprintf(__('Showing top 10 of %d — use Export to Excel for the full list.', 'impact360'), count($allCves)) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

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
