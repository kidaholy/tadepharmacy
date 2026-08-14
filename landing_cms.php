<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/landing_lib.php';

requirePermission('landing.edit');

$pdo = getDB();
$msg = '';
$error = '';
$allFields = array_merge(landingFieldDefs(), landingFeatureDefs());
$publicImages = listPublicImages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [];
    foreach ($allFields as $key => $def) {
        $data[$key] = trim($_POST[$key] ?? '');
    }

    if (!empty($_FILES['hero_upload']['name']) && ($_FILES['hero_upload']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['hero_upload']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $error = 'Hero image must be JPG, PNG, or WebP.';
        } else {
            $dir = __DIR__ . '/public';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $safeName = 'landing-hero-' . date('Ymd-His') . '.' . $ext;
            $dest = $dir . '/' . $safeName;
            if (move_uploaded_file($_FILES['hero_upload']['tmp_name'], $dest)) {
                $data['landing_hero_image'] = 'public/' . $safeName;
            } else {
                $error = 'Could not upload hero image.';
            }
        }
    }

    if ($error === '') {
        saveLandingSettings($pdo, $data);
        $msg = 'Landing page content saved successfully.';
    }
}

$landing = getLandingContent();
$sections = [
    'hero'     => 'Hero section',
    'about'    => 'About & mission',
    'info'     => 'Hours & services intro',
    'stats'    => 'Highlight stats',
    'features' => 'Services & features',
];

renderHead('Landing Page CMS');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Landing Page', 'Manage public welcome page content'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="alert-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid-2 landing-cms-grid">
  <div style="display:flex;flex-direction:column;gap:20px;">
    <form method="POST" enctype="multipart/form-data" class="landing-cms-form">
      <?php foreach ($sections as $sectionKey => $sectionTitle): ?>
      <div class="card">
        <div class="card-header">
          <span class="card-title"><?= htmlspecialchars($sectionTitle) ?></span>
        </div>
        <?php foreach ($allFields as $key => $def): ?>
          <?php if (($def['section'] ?? '') !== $sectionKey) continue; ?>
          <div class="form-group">
            <label><?= htmlspecialchars($def['label']) ?>
              <?php if (!empty($def['hint'])): ?>
                <span style="color:var(--text-300);font-weight:400;text-transform:none;font-size:11px;">(<?= htmlspecialchars($def['hint']) ?>)</span>
              <?php endif; ?>
            </label>
            <?php if ($key === 'landing_hero_image'): ?>
              <select name="landing_hero_image" style="margin-bottom:8px;">
                <?php foreach ($publicImages as $imgPath): ?>
                <option value="<?= htmlspecialchars($imgPath) ?>" <?= $landing['landing_hero_image'] === $imgPath ? 'selected' : '' ?>>
                  <?= htmlspecialchars(basename($imgPath)) ?>
                </option>
                <?php endforeach; ?>
                <?php if ($landing['landing_hero_image'] && !in_array($landing['landing_hero_image'], $publicImages, true)): ?>
                <option value="<?= htmlspecialchars($landing['landing_hero_image']) ?>" selected><?= htmlspecialchars($landing['landing_hero_image']) ?></option>
                <?php endif; ?>
              </select>
              <input type="file" name="hero_upload" accept="image/jpeg,image/png,image/webp">
              <p style="font-size:11px;color:var(--text-300);margin-top:6px;">Upload replaces the selected hero image path after save.</p>
            <?php elseif ($def['type'] === 'textarea'): ?>
              <textarea name="<?= htmlspecialchars($key) ?>" rows="<?= str_contains($key, '_desc') || str_contains($key, 'about') || str_contains($key, 'mission') ? 3 : 4 ?>"><?= htmlspecialchars($landing[$key] ?? '') ?></textarea>
            <?php else: ?>
              <input type="text" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($landing[$key] ?? '') ?>">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save landing page</button>
        <a href="welcome.php" target="_blank" class="btn btn-ghost"><i data-lucide="external-link"></i> Preview live page</a>
      </div>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><span class="card-title">Live preview</span></div>
      <div class="landing-cms-preview">
        <img src="<?= htmlspecialchars(landingHeroImageUrl($landing)) ?>" alt="Hero preview" class="landing-cms-preview-img">
        <p class="welcome-eyebrow" style="margin-top:14px;"><?= htmlspecialchars($landing['landing_eyebrow']) ?></p>
        <h2 style="font-size:22px;font-weight:800;margin-bottom:10px;"><?= htmlspecialchars($landing['landing_headline']) ?></h2>
        <p style="font-size:13px;color:var(--text-200);line-height:1.55;"><?= nl2br(htmlspecialchars($landing['landing_lead'])) ?></p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Tips</span></div>
      <ul class="landing-cms-tips">
        <li>Content appears on the public <strong>welcome.php</strong> page before login.</li>
        <li>Contact phone, email, and address pull from <a href="settings.php">Settings</a>.</li>
        <li>Place images in the <code>public/</code> folder or upload a new hero image above.</li>
        <li>Lucide icon names: <a href="https://lucide.dev/icons/" target="_blank" rel="noopener">lucide.dev/icons</a></li>
      </ul>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Public assets</span></div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($publicImages as $imgPath): ?>
        <div style="display:flex;align-items:center;gap:10px;font-size:12px;">
          <img src="<?= htmlspecialchars(publicAssetUrl($imgPath)) ?>" alt="" style="width:48px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">
          <code style="color:var(--text-200);"><?= htmlspecialchars($imgPath) ?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
