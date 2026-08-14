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

$notifyFields = [
    'telegram_enabled'      => ['label' => 'Enable Telegram Alerts', 'type' => 'checkbox'],
    'telegram_bot_token'    => ['label' => 'Telegram Bot Token',     'type' => 'text'],
    'telegram_chat_id'      => ['label' => 'Telegram Chat ID',       'type' => 'text'],
    'whatsapp_enabled'      => ['label' => 'Enable WhatsApp Alerts', 'type' => 'checkbox'],
    'whatsapp_api_url'      => ['label' => 'WhatsApp API URL',       'type' => 'text'],
    'whatsapp_notify_phone' => ['label' => 'WhatsApp Notify Phone',  'type' => 'tel'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    foreach (array_merge($fields, $notifyFields) as $key => $def) {
        if ($def['type'] === 'checkbox') {
            $val = isset($_POST[$key]) ? '1' : '0';
        } else {
            $val = trim($_POST[$key] ?? '');
        }
        $stmt->execute([$key, $val]);
    }
    $msg = 'Settings saved successfully!';
}

// Reload
$settings = [];
foreach (array_merge($fields, $notifyFields) as $key => $def) {
    $settings[$key] = getSetting($key, $def['type'] === 'checkbox' ? '0' : '');
}

// DB stats
$dbStats = [
    'Medicines'  => $pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn(),
    'Batches'    => $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn(),
    'Sales'      => $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn(),
    'Customers'  => $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
    'Purchases'  => $pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn(),
];

renderHead('Settings');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Settings', 'System configuration'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php $flash = flashGet(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> auto-hide">
  <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

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
      <?php foreach ($notifyFields as $key => $def): ?>
      <?php if ($def['type'] === 'checkbox'): ?>
      <input type="hidden" name="<?= $key ?>" value="0">
      <?php endif; ?>
      <?php endforeach; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Settings</button>
      </div>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:20px;">
  <!-- Notifications -->
  <div class="card">
    <div class="card-header"><span class="card-title">Notification Settings</span></div>
    <form method="POST">
      <?php foreach ($notifyFields as $key => $def): ?>
      <div class="form-group">
        <?php if ($def['type'] === 'checkbox'): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
          <input type="checkbox" name="<?= $key ?>" value="1" <?= $settings[$key] === '1' ? 'checked' : '' ?> style="width:auto;">
          <?= htmlspecialchars($def['label']) ?>
        </label>
        <?php else: ?>
        <label><?= htmlspecialchars($def['label']) ?></label>
        <input type="<?= $def['type'] ?>" name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key]) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php foreach ($fields as $key => $def): ?>
      <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key]) ?>">
      <?php endforeach; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i data-lucide="bell"></i> Save Notifications</button>
      </div>
    </form>
    <p style="font-size:12px;color:var(--text-300);margin-top:12px;">Alerts: new credit sale, credit due tomorrow, overdue credit, low stock, out of stock.</p>
  </div>

  <!-- DB Stats & Info -->
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

    <div class="card" style="border-color:rgba(79,142,247,0.35);margin-bottom:20px;">
      <div class="card-header"><span class="card-title" style="color:var(--accent-dark);">Demo Data</span></div>
      <p style="font-size:13px;color:var(--text-300);margin-bottom:16px;">
        Load sample customers, suppliers, purchases, sales (all payment methods), credit scenarios, and expenses
        so you can test Reports, Dashboard, POS, and Customers.
      </p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="POST" action="actions.php">
          <input type="hidden" name="act" value="seed_demo">
          <button type="submit" class="btn btn-primary"><i data-lucide="database"></i> Load Demo Data</button>
        </form>
        <form method="POST" action="actions.php" onsubmit="return confirm('Replace existing demo data? This clears demo sales, purchases, batches, and re-seeds.')">
          <input type="hidden" name="act" value="seed_demo">
          <input type="hidden" name="force" value="1">
          <button type="submit" class="btn btn-ghost">Replace Demo Data</button>
        </form>
        <form method="POST" action="actions.php" onsubmit="return confirm('Remove all demo sales, purchases, batches, customers, suppliers, and expenses?')">
          <input type="hidden" name="act" value="clear_demo">
          <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i> Remove Demo Data</button>
        </form>
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
