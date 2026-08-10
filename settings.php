<?php
require_once __DIR__ . '/layout.php';

$pdo = getDB();
$msg = '';

$fields = [
    'pharmacy_name'    => ['label' => 'Pharmacy Name',    'type' => 'text'],
    'pharmacy_phone'   => ['label' => 'Phone Number',     'type' => 'tel'],
    'pharmacy_email'   => ['label' => 'Email',            'type' => 'email'],
    'pharmacy_address' => ['label' => 'Address',          'type' => 'textarea'],
    'currency'         => ['label' => 'Currency Code',    'type' => 'text', 'hint' => 'e.g. ETB, USD, EUR'],
    'tax_rate'         => ['label' => 'Tax Rate (%)',     'type' => 'number', 'hint' => 'Set to 0 for no tax'],
    'receipt_footer'   => ['label' => 'Receipt Footer',  'type' => 'textarea'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    foreach ($fields as $key => $def) {
        $val = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $val]);
    }
    $msg = 'Settings saved successfully!';
}

// Reload
$settings = [];
foreach ($fields as $key => $def) {
    $settings[$key] = getSetting($key, '');
}

// DB stats
$dbStats = [
    'Medicines'  => $pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn(),
    'Batches'    => $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn(),
    'Sales'      => $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn(),
    'Purchases'  => $pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn(),
    'Suppliers'  => $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn(),
];

renderHead('Settings');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Settings', 'System configuration'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="grid-2">
  <!-- Settings Form -->
  <div class="card">
    <div class="card-header"><span class="card-title">Pharmacy Information</span></div>
    <form method="POST">
      <?php foreach ($fields as $key => $def): ?>
      <div class="form-group">
        <label><?= htmlspecialchars($def['label']) ?><?= isset($def['hint']) ? ' <span style="color:var(--text-300);font-weight:400;text-transform:none;font-size:11px;">(' . $def['hint'] . ')</span>' : '' ?></label>
        <?php if ($def['type'] === 'textarea'): ?>
          <textarea name="<?= $key ?>"><?= htmlspecialchars($settings[$key]) ?></textarea>
        <?php else: ?>
          <input type="<?= $def['type'] ?>" name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key]) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Settings</button>
      </div>
    </form>
  </div>

  <!-- DB Stats & Info -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><span class="card-title">Database Summary</span></div>
      <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($dbStats as $label => $count): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text-200);font-size:14px;"><?= $label ?></span>
          <span class="badge badge-blue"><?= number_format($count) ?> records</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">System Info</span></div>
      <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;">
        <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-300);">PHP Version</span><span style="color:var(--accent2);"><?= PHP_VERSION ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-300);">SQLite Version</span><span style="color:var(--accent2);"><?= SQLite3::version()['versionString'] ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-300);">Database File</span><span style="color:var(--text-200);font-size:11px;">data/pharmacy.db</span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-300);">DB Size</span><span style="color:var(--accent2);"><?= file_exists(DB_PATH) ? number_format(filesize(DB_PATH)/1024, 1) . ' KB' : '—' ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-300);">Server Time</span><span style="color:var(--text-200);"><?= date('Y-m-d H:i:s') ?></span></div>
      </div>
    </div>

    <div class="card" style="border-color:rgba(242,95,92,0.3);">
      <div class="card-header"><span class="card-title" style="color:var(--danger);">Danger Zone</span></div>
      <p style="font-size:13px;color:var(--text-300);margin-bottom:16px;">These actions are irreversible. Proceed with caution.</p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <form method="POST" action="actions.php" onsubmit="return confirm('Clear ALL sales data? This cannot be undone!')">
          <input type="hidden" name="act" value="clear_sales">
          <button type="submit" class="btn btn-danger" style="width:100%;">
            <i data-lucide="trash-2"></i> Clear All Sales Data
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
