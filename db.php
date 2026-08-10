<?php
define('DB_PATH', __DIR__ . '/data/pharmacy.db');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        initDB($pdo);
    }
    return $pdo;
}

function initDB(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            address TEXT,
            contact_person TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS medicines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            generic_name TEXT,
            category_id INTEGER,
            unit TEXT DEFAULT 'pcs',
            description TEXT,
            reorder_level INTEGER DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            medicine_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            selling_price REAL NOT NULL DEFAULT 0,
            expiry_date DATE NOT NULL,
            manufacture_date DATE,
            supplier_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT NOT NULL UNIQUE,
            customer_name TEXT DEFAULT 'Walk-in Customer',
            customer_phone TEXT,
            total_amount REAL NOT NULL DEFAULT 0,
            discount REAL DEFAULT 0,
            paid_amount REAL DEFAULT 0,
            payment_method TEXT DEFAULT 'cash',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id),
            FOREIGN KEY (batch_id) REFERENCES batches(id)
        );

        CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT,
            supplier_id INTEGER,
            total_amount REAL DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS purchase_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_id INTEGER NOT NULL,
            medicine_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            purchase_price REAL NOT NULL,
            selling_price REAL NOT NULL,
            expiry_date DATE NOT NULL,
            FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");

    // Seed default settings
    $defaults = [
        'pharmacy_name'    => 'TADE PHARMACY',
        'pharmacy_phone'   => '+251 912 345 678',
        'pharmacy_email'   => 'tade@pharmacy.com',
        'pharmacy_address' => 'Addis Ababa, Ethiopia',
        'currency'         => 'ETB',
        'tax_rate'         => '0',
        'receipt_footer'   => 'Thank you for choosing TADE PHARMACY!',
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // Seed categories
    $cats = ['Antibiotics','Analgesics','Antifungals','Vitamins & Supplements','Antihypertensives','Antidiabetics','Antihistamines','Dermatology','Gastrointestinal','Other'];
    $catStmt = $pdo->prepare("INSERT OR IGNORE INTO categories (name) VALUES (?)");
    foreach ($cats as $c) $catStmt->execute([$c]);
}

function getSetting(string $key, string $default = ''): string {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function generateInvoice(): string {
    return 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function currency(float $amount): string {
    $cur = getSetting('currency', 'ETB');
    return $cur . ' ' . number_format($amount, 2);
}
?>
