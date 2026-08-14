<?php
require_once __DIR__ . '/db.php';

define('LANDING_HERO_DEFAULT', 'public/landing page.jpg');

function publicAssetUrl(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '') {
        return '';
    }
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function landingFieldDefs(): array {
    return [
        'landing_eyebrow'        => ['label' => 'Hero eyebrow', 'type' => 'text', 'section' => 'hero'],
        'landing_headline'       => ['label' => 'Hero headline', 'type' => 'text', 'section' => 'hero'],
        'landing_lead'           => ['label' => 'Hero introduction', 'type' => 'textarea', 'section' => 'hero'],
        'landing_hero_image'     => ['label' => 'Hero image path', 'type' => 'text', 'section' => 'hero', 'hint' => 'Relative path, e.g. public/landing page.jpg'],
        'landing_cta_primary'    => ['label' => 'Primary button label', 'type' => 'text', 'section' => 'hero'],
        'landing_cta_secondary'  => ['label' => 'Secondary button label', 'type' => 'text', 'section' => 'hero'],

        'landing_about_title'    => ['label' => 'About section title', 'type' => 'text', 'section' => 'about'],
        'landing_about'          => ['label' => 'About text', 'type' => 'textarea', 'section' => 'about'],
        'landing_mission'        => ['label' => 'Mission statement', 'type' => 'textarea', 'section' => 'about'],

        'landing_hours'          => ['label' => 'Opening hours', 'type' => 'textarea', 'section' => 'info', 'hint' => 'One line per schedule row'],
        'landing_services_intro' => ['label' => 'Services intro', 'type' => 'textarea', 'section' => 'info'],

        'landing_stat_1_value'   => ['label' => 'Stat 1 value', 'type' => 'text', 'section' => 'stats'],
        'landing_stat_1_label'   => ['label' => 'Stat 1 label', 'type' => 'text', 'section' => 'stats'],
        'landing_stat_2_value'   => ['label' => 'Stat 2 value', 'type' => 'text', 'section' => 'stats'],
        'landing_stat_2_label'   => ['label' => 'Stat 2 label', 'type' => 'text', 'section' => 'stats'],
        'landing_stat_3_value'   => ['label' => 'Stat 3 value', 'type' => 'text', 'section' => 'stats'],
        'landing_stat_3_label'   => ['label' => 'Stat 3 label', 'type' => 'text', 'section' => 'stats'],
    ];
}

function landingFeatureDefs(): array {
    $defs = [];
    for ($i = 1; $i <= 6; $i++) {
        $defs["landing_feature_{$i}_title"] = ['label' => "Feature $i title", 'type' => 'text', 'section' => 'features'];
        $defs["landing_feature_{$i}_desc"]  = ['label' => "Feature $i description", 'type' => 'textarea', 'section' => 'features'];
        $defs["landing_feature_{$i}_icon"]  = ['label' => "Feature $i icon", 'type' => 'text', 'section' => 'features', 'hint' => 'Lucide icon name, e.g. pill, heart-pulse'];
    }
    return $defs;
}

function landingDefaults(): array {
    return [
        'landing_eyebrow'        => 'Trusted community pharmacy · Addis Ababa',
        'landing_headline'       => 'Quality medicines, expert care',
        'landing_lead'           => 'TADE Pharmacy delivers safe, affordable medicines and professional guidance for families, clinics, and chronic-care patients across Ethiopia.',
        'landing_hero_image'     => LANDING_HERO_DEFAULT,
        'landing_cta_primary'    => 'Team Login',
        'landing_cta_secondary'  => 'View services',

        'landing_about_title'    => 'About TADE Pharmacy',
        'landing_about'          => "We are a full-service pharmacy committed to making healthcare accessible. From everyday wellness products to specialized prescriptions, our team ensures every item is authentic, properly stored, and dispensed with clear instructions.\n\nWe partner with trusted suppliers, monitor batch expiry (FEFO), and maintain inventory you can rely on — whether you visit in person or work with our managers, pharmacists, and cashiers through the team portal.",
        'landing_mission'        => 'Our mission is to combine modern pharmacy management with compassionate service, so every customer leaves informed, supported, and confident in their treatment.',

        'landing_hours'          => "Monday – Friday: 8:00 AM – 8:00 PM\nSaturday: 8:00 AM – 6:00 PM\nSunday: 9:00 AM – 2:00 PM\nPublic holidays: limited hours — call ahead",
        'landing_services_intro' => 'Everything you need from a modern pharmacy — dispensing, inventory-backed stock, credit accounts for registered customers, and digital reporting for managers, pharmacists, and cashiers.',

        'landing_stat_1_value'   => '15+',
        'landing_stat_1_label'   => 'Years serving the community',
        'landing_stat_2_value'   => '5,000+',
        'landing_stat_2_label'   => 'Medicines & health products',
        'landing_stat_3_value'   => '100%',
        'landing_stat_3_label'   => 'Authentic sourced stock',

        'landing_feature_1_title' => 'Prescription dispensing',
        'landing_feature_1_desc'  => 'Accurate dispensing with batch tracking, expiry checks, and patient counselling.',
        'landing_feature_1_icon'  => 'pill',

        'landing_feature_2_title' => 'OTC & wellness',
        'landing_feature_2_desc'  => 'Vitamins, pain relief, allergy care, diabetes supplies, and everyday health essentials.',
        'landing_feature_2_icon'  => 'heart-pulse',

        'landing_feature_3_title' => 'Chronic care support',
        'landing_feature_3_desc'  => 'Reliable refills for hypertension, diabetes, and other long-term treatment plans.',
        'landing_feature_3_icon'  => 'activity',

        'landing_feature_4_title' => 'Inventory & cold chain',
        'landing_feature_4_desc'  => 'Proper storage, FEFO rotation, and low-stock monitoring for safe products.',
        'landing_feature_4_icon'  => 'thermometer',

        'landing_feature_5_title' => 'Credit for registered customers',
        'landing_feature_5_desc'  => 'Managed credit accounts with clear due dates and payment history.',
        'landing_feature_5_icon'  => 'wallet',

        'landing_feature_6_title' => 'Business & clinic supply',
        'landing_feature_6_desc'  => 'Bulk orders and recurring supply arrangements for clinics and institutions.',
        'landing_feature_6_icon'  => 'building-2',
    ];
}

function getLandingContent(): array {
    $content = [];
    foreach (array_merge(landingFieldDefs(), landingFeatureDefs()) as $key => $def) {
        $content[$key] = getSetting($key, landingDefaults()[$key] ?? '');
    }
    return $content;
}

function landingHeroImagePath(array $landing = null): string {
    $landing = $landing ?? getLandingContent();
    $path = trim($landing['landing_hero_image'] ?? LANDING_HERO_DEFAULT);
    if ($path === '') {
        $path = LANDING_HERO_DEFAULT;
    }
    $full = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    if (!is_file($full)) {
        $path = LANDING_HERO_DEFAULT;
    }
    return $path;
}

function landingHeroImageUrl(array $landing = null): string {
    return publicAssetUrl(landingHeroImagePath($landing));
}

function landingFeatures(array $landing): array {
    $features = [];
    for ($i = 1; $i <= 6; $i++) {
        $title = trim($landing["landing_feature_{$i}_title"] ?? '');
        if ($title === '') {
            continue;
        }
        $features[] = [
            'title' => $title,
            'desc'  => $landing["landing_feature_{$i}_desc"] ?? '',
            'icon'  => preg_replace('/[^a-z0-9\-]/', '', $landing["landing_feature_{$i}_icon"] ?? 'circle') ?: 'circle',
        ];
    }
    return $features;
}

function landingStats(array $landing): array {
    $stats = [];
    for ($i = 1; $i <= 3; $i++) {
        $value = trim($landing["landing_stat_{$i}_value"] ?? '');
        $label = trim($landing["landing_stat_{$i}_label"] ?? '');
        if ($value === '' && $label === '') {
            continue;
        }
        $stats[] = ['value' => $value, 'label' => $label];
    }
    return $stats;
}

function listPublicImages(): array {
    $dir = __DIR__ . '/public';
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) {
            $files[] = 'public/' . $file;
        }
    }
    sort($files);
    return $files;
}

function saveLandingSettings(PDO $pdo, array $data): void {
    $keys = array_keys(array_merge(landingFieldDefs(), landingFeatureDefs()));
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $stmt->execute([$key, $data[$key]]);
        }
    }
}

function seedLandingDefaults(PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach (landingDefaults() as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}
