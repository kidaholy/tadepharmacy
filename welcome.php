<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/landing_lib.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');
$phone        = getSetting('pharmacy_phone');
$email        = getSetting('pharmacy_email');
$address      = getSetting('pharmacy_address');
$landing      = getLandingContent();
$features     = landingFeatures($landing);
$stats        = landingStats($landing);
$heroUrl      = landingHeroImageUrl($landing);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pharmacyName) ?> — Welcome</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/style.css">
  <?php renderPharmacyFavicon(); ?>
</head>
<body class="auth-body">
  <div class="auth-bg"></div>
  <div class="welcome-page welcome-page--full">
    <header class="welcome-header">
      <div class="welcome-brand">
        <?php renderPharmacyLogo('logo-icon--md'); ?>
        <div class="logo-text">
          <span class="logo-main"><?= htmlspecialchars($pharmacyName) ?></span>
          <span class="logo-sub">Pharmacy &amp; Health Care</span>
        </div>
      </div>
      <a href="login.php" class="btn btn-primary btn-sm"><i data-lucide="log-in"></i> Staff login</a>
    </header>

    <section class="welcome-hero welcome-hero--photo">
      <div class="welcome-hero-content">
        <p class="welcome-eyebrow"><?= htmlspecialchars($landing['landing_eyebrow']) ?></p>
        <h1><?= htmlspecialchars($landing['landing_headline']) ?><br><span><?= htmlspecialchars($pharmacyName) ?></span></h1>
        <p class="welcome-lead"><?= nl2br(htmlspecialchars($landing['landing_lead'])) ?></p>
        <div class="welcome-actions">
          <a href="login.php" class="btn btn-primary">
            <i data-lucide="log-in"></i> <?= htmlspecialchars($landing['landing_cta_primary']) ?>
          </a>
          <a href="#services" class="btn btn-ghost">
            <i data-lucide="arrow-down"></i> <?= htmlspecialchars($landing['landing_cta_secondary']) ?>
          </a>
        </div>
        <?php if ($stats): ?>
        <div class="welcome-stats">
          <?php foreach ($stats as $stat): ?>
          <div class="welcome-stat">
            <strong><?= htmlspecialchars($stat['value']) ?></strong>
            <span><?= htmlspecialchars($stat['label']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="welcome-hero-visual">
        <div class="welcome-hero-frame">
          <img src="<?= htmlspecialchars($heroUrl) ?>" alt="<?= htmlspecialchars($pharmacyName) ?> pharmacy" class="welcome-hero-image">
        </div>
        <?php if ($phone || $address): ?>
        <div class="welcome-hero-badge">
          <i data-lucide="map-pin"></i>
          <div>
            <?php if ($address): ?><strong><?= htmlspecialchars($address) ?></strong><?php endif; ?>
            <?php if ($phone): ?><span><?= htmlspecialchars($phone) ?></span><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="welcome-contact-bar">
      <?php if ($phone): ?>
      <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $phone)) ?>" class="welcome-contact-item">
        <i data-lucide="phone"></i>
        <div><span>Call us</span><strong><?= htmlspecialchars($phone) ?></strong></div>
      </a>
      <?php endif; ?>
      <?php if ($email): ?>
      <a href="mailto:<?= htmlspecialchars($email) ?>" class="welcome-contact-item">
        <i data-lucide="mail"></i>
        <div><span>Email</span><strong><?= htmlspecialchars($email) ?></strong></div>
      </a>
      <?php endif; ?>
      <?php if ($address): ?>
      <div class="welcome-contact-item">
        <i data-lucide="map-pin"></i>
        <div><span>Location</span><strong><?= htmlspecialchars($address) ?></strong></div>
      </div>
      <?php endif; ?>
      <?php if (trim($landing['landing_hours'])): ?>
      <div class="welcome-contact-item">
        <i data-lucide="clock"></i>
        <div><span>Opening hours</span><strong><?= nl2br(htmlspecialchars(trim(explode("\n", $landing['landing_hours'])[0]))) ?></strong></div>
      </div>
      <?php endif; ?>
    </section>

    <section class="welcome-about" id="about">
      <div class="welcome-about-copy">
        <h2><?= htmlspecialchars($landing['landing_about_title']) ?></h2>
        <div class="welcome-about-text"><?= nl2br(htmlspecialchars($landing['landing_about'])) ?></div>
        <?php if (trim($landing['landing_mission'])): ?>
        <blockquote class="welcome-mission">
          <i data-lucide="quote"></i>
          <?= nl2br(htmlspecialchars($landing['landing_mission'])) ?>
        </blockquote>
        <?php endif; ?>
      </div>
      <div class="welcome-hours-card">
        <h3><i data-lucide="clock"></i> Opening hours</h3>
        <ul>
          <?php foreach (array_filter(array_map('trim', explode("\n", $landing['landing_hours']))) as $line): ?>
          <li><?= htmlspecialchars($line) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if (trim($landing['landing_services_intro'])): ?>
        <p class="welcome-hours-note"><?= nl2br(htmlspecialchars($landing['landing_services_intro'])) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <section class="welcome-services" id="services">
      <div class="welcome-section-head">
        <p class="welcome-eyebrow">What we offer</p>
        <h2>Pharmacy services &amp; support</h2>
        <p><?= nl2br(htmlspecialchars($landing['landing_services_intro'])) ?></p>
      </div>
      <div class="welcome-services-grid">
        <?php
        $colors = ['blue', 'green', 'orange', 'blue', 'green', 'orange'];
        foreach ($features as $idx => $feature):
          $color = $colors[$idx % count($colors)];
        ?>
        <article class="welcome-service-card">
          <div class="welcome-feature-icon <?= $color ?>"><i data-lucide="<?= htmlspecialchars($feature['icon']) ?>"></i></div>
          <h3><?= htmlspecialchars($feature['title']) ?></h3>
          <p><?= nl2br(htmlspecialchars($feature['desc'])) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="welcome-cta">
      <div>
        <h2>Ready to manage pharmacy operations?</h2>
        <p>Staff can sign in to access POS, inventory, reports, customer credit, and more.</p>
      </div>
      <a href="login.php" class="btn btn-primary btn-lg"><i data-lucide="shield-check"></i> Go to staff portal</a>
    </section>

    <footer class="welcome-footer">
      <div class="welcome-footer-inner">
        <div>
          <strong><?= htmlspecialchars($pharmacyName) ?></strong>
          <?php if ($address): ?><span><?= htmlspecialchars($address) ?></span><?php endif; ?>
        </div>
        <div>
          <?php if ($phone): ?><span><?= htmlspecialchars($phone) ?></span><?php endif; ?>
          <?php if ($email): ?><span><?= htmlspecialchars($email) ?></span><?php endif; ?>
        </div>
      </div>
      <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($pharmacyName) ?>. All rights reserved.</p>
    </footer>
  </div>
  <script>lucide.createIcons();</script>
</body>
</html>
