<?php
date_default_timezone_set('Africa/Addis_Ababa');

define('DB_PATH', __DIR__ . '/data/pharmacy.db');
define('PHARMACY_LOGO_PATH', __DIR__ . '/public/tade pharmacy.jpeg');

function pharmacyLogoUrl(): string {
    return 'public/' . rawurlencode('tade pharmacy.jpeg');
}

function pharmacyLogoExists(): bool {
    return is_file(PHARMACY_LOGO_PATH);
}

function renderPharmacyLogo(string $extraClass = ''): void {
    $cls = 'logo-icon' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass) : '');
    $alt = htmlspecialchars(getSetting('pharmacy_name', 'TADE PHARMACY'));
    if (pharmacyLogoExists()) {
        echo '<div class="' . $cls . '"><img src="' . htmlspecialchars(pharmacyLogoUrl()) . '" alt="' . $alt . '" class="logo-img"></div>';
        return;
    }
    echo '<div class="' . $cls . '"><i data-lucide="cross" style="width:20px;height:20px;"></i></div>';
}

function renderPharmacyFavicon(): void {
    if (!pharmacyLogoExists()) {
        return;
    }
    echo '<link rel="icon" type="image/jpeg" href="' . htmlspecialchars(pharmacyLogoUrl()) . '">' . "\n";
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Durability & concurrency pragmas — explicit so behavior is stable
        // regardless of PHP/driver defaults, across restarts and concurrent requests.
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=FULL');      // fsync on every commit: no lost transactions on power loss
        $pdo->exec('PRAGMA foreign_keys=ON');       // enforce FKs per connection
        $pdo->exec('PRAGMA busy_timeout=60000');    // wait up to 60s for a write lock instead of failing
        $pdo->exec('PRAGMA wal_autocheckpoint=1000');
        $pdo->exec('PRAGMA journal_size_limit=67108864'); // cap WAL growth at 64MB
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
            strength TEXT,
            dosage_form TEXT,
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

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'staff',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS operating_expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            description TEXT,
            amount REAL NOT NULL DEFAULT 0,
            expense_date DATE NOT NULL,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone TEXT NOT NULL UNIQUE,
            outstanding_balance REAL NOT NULL DEFAULT 0,
            total_credit REAL NOT NULL DEFAULT 0,
            total_paid REAL NOT NULL DEFAULT 0,
            overdue_amount REAL NOT NULL DEFAULT 0,
            credit_limit REAL NOT NULL DEFAULT 0,
            last_credit_sale DATETIME,
            next_due_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS payment_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            customer_id INTEGER,
            amount REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL DEFAULT 'cash',
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            reference_number TEXT,
            received_by INTEGER,
            notes TEXT,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    foreach ([
        'ALTER TABLE sales ADD COLUMN user_id INTEGER REFERENCES users(id)',
        'ALTER TABLE sales ADD COLUMN sale_type TEXT DEFAULT \'cash\'',
        'ALTER TABLE sales ADD COLUMN credit_due_date DATE',
        'ALTER TABLE sales ADD COLUMN branch_id INTEGER',
        'ALTER TABLE medicines ADD COLUMN barcode TEXT',
        'ALTER TABLE medicines ADD COLUMN sku TEXT',
        'ALTER TABLE sales ADD COLUMN customer_id INTEGER REFERENCES customers(id)',
        'ALTER TABLE sales ADD COLUMN payment_status TEXT DEFAULT \'paid\'',
        'ALTER TABLE sales ADD COLUMN due_date DATE',
        'ALTER TABLE sales ADD COLUMN remaining_balance REAL DEFAULT 0',
        'ALTER TABLE sales ADD COLUMN payment_reference TEXT',
        'ALTER TABLE sales ADD COLUMN credit_notes TEXT',
        'ALTER TABLE medicines ADD COLUMN product_type TEXT DEFAULT \'medicine\'',
    ] as $migration) {
        try { $pdo->exec($migration); } catch (PDOException $e) { /* already applied */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sale_returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            quantity INTEGER NOT NULL DEFAULT 0,
            amount REAL NOT NULL DEFAULT 0,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id),
            FOREIGN KEY (batch_id) REFERENCES batches(id)
        )
    ");

    // Seed default admin (username: admin / password: admin123)
    $userCount = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0) {
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)")
            ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrator', 'admin']);
    }

    // Seed default settings
    $defaults = [
        'pharmacy_name'    => 'TADE PHARMACY',
        'pharmacy_phone'   => '+251 912 345 678',
        'pharmacy_email'   => 'tade@pharmacy.com',
        'pharmacy_address' => 'Addis Ababa, Ethiopia',
        'currency'         => 'ETB',
        'tax_rate'         => '0',
        'receipt_footer'   => 'Thank you for choosing TADE PHARMACY!',
        'telegram_daily_report' => '1',
        'telegram_report_time_1' => '09:00',
        'telegram_report_time_2' => '18:00',
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    require_once __DIR__ . '/landing_lib.php';
    seedLandingDefaults($pdo);

    // Seed categories
    $cats = [
        'Antibiotics & Antimicrobials',
        'Cardiovascular & Blood Health',
        'Diabetes & Endocrine Care',
        'Pain Relief & Anti-Inflammatories',
        'Respiratory & Allergy',
        'Gastrointestinal Health',
        'Dermatology & Skin Care',
        'Cosmetics',
        'Equipment',
        'Other',
    ];
    $catStmt = $pdo->prepare("INSERT OR IGNORE INTO categories (name) VALUES (?)");
    foreach ($cats as $c) $catStmt->execute([$c]);

    // Indexes for list/search performance
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_medicines_name ON medicines(name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_medicines_category ON medicines(category_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_medicine ON batches(medicine_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_expiry ON batches(expiry_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_created ON sales(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_invoice ON sales(invoice_number)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_created ON purchases(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchase_items_purchase ON purchase_items(purchase_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_user ON sales(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_supplier ON batches(supplier_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_expenses_date ON operating_expenses(expense_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_customer ON sales(customer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_payment_status ON sales(payment_status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payment_history_sale ON payment_history(sale_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payment_history_customer ON payment_history(customer_id)");

    require_once __DIR__ . '/permissions_lib.php';
    initPermissionsSchema($pdo);
    initPurchaseModuleSchema($pdo);
}

function initPurchaseModuleSchema(PDO $pdo): void {
    foreach ([
        "ALTER TABLE suppliers ADD COLUMN supplier_code TEXT",
        "ALTER TABLE suppliers ADD COLUMN company_name TEXT",
        "ALTER TABLE suppliers ADD COLUMN tax_number TEXT",
        "ALTER TABLE suppliers ADD COLUMN payment_terms TEXT DEFAULT '30'",
        "ALTER TABLE suppliers ADD COLUMN default_credit_days INTEGER DEFAULT 30",
        "ALTER TABLE suppliers ADD COLUMN opening_balance REAL DEFAULT 0",
        "ALTER TABLE suppliers ADD COLUMN status TEXT DEFAULT 'active'",
        "ALTER TABLE suppliers ADD COLUMN notes TEXT",
        "ALTER TABLE suppliers ADD COLUMN updated_at DATETIME",
        "ALTER TABLE purchases ADD COLUMN purchase_number TEXT",
        "ALTER TABLE purchases ADD COLUMN purchase_date DATE",
        "ALTER TABLE purchases ADD COLUMN due_date DATE",
        "ALTER TABLE purchases ADD COLUMN payment_terms TEXT DEFAULT '30'",
        "ALTER TABLE purchases ADD COLUMN subtotal REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN discount REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN tax REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN grand_total REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN total_paid REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN total_due REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN total_returned REAL DEFAULT 0",
        "ALTER TABLE purchases ADD COLUMN payment_status TEXT DEFAULT 'unpaid'",
        "ALTER TABLE purchases ADD COLUMN status TEXT DEFAULT 'received'",
        "ALTER TABLE purchases ADD COLUMN warehouse TEXT DEFAULT 'Main Store'",
        "ALTER TABLE purchases ADD COLUMN created_by INTEGER",
        "ALTER TABLE purchases ADD COLUMN received_at DATETIME",
        "ALTER TABLE purchases ADD COLUMN approved_by INTEGER",
        "ALTER TABLE purchases ADD COLUMN approved_at DATETIME",
        "ALTER TABLE purchases ADD COLUMN updated_at DATETIME",
        "ALTER TABLE purchase_items ADD COLUMN batch_id INTEGER",
        "ALTER TABLE purchase_items ADD COLUMN manufacturing_date DATE",
        "ALTER TABLE purchase_items ADD COLUMN free_quantity INTEGER DEFAULT 0",
        "ALTER TABLE purchase_items ADD COLUMN discount REAL DEFAULT 0",
        "ALTER TABLE purchase_items ADD COLUMN tax REAL DEFAULT 0",
        "ALTER TABLE purchase_items ADD COLUMN line_total REAL DEFAULT 0",
        "ALTER TABLE purchase_items ADD COLUMN returned_quantity INTEGER DEFAULT 0",
        "ALTER TABLE batches ADD COLUMN purchase_id INTEGER",
        "ALTER TABLE batches ADD COLUMN quantity_received INTEGER DEFAULT 0",
        "ALTER TABLE batches ADD COLUMN free_quantity INTEGER DEFAULT 0",
        "ALTER TABLE batches ADD COLUMN status TEXT DEFAULT 'active'",
        "ALTER TABLE batches ADD COLUMN variant TEXT",
        "ALTER TABLE batches ADD COLUMN model_number TEXT",
        "ALTER TABLE batches ADD COLUMN serial_number TEXT",
        "ALTER TABLE batches ADD COLUMN warranty_period TEXT",
        "ALTER TABLE batches ADD COLUMN warranty_expiry DATE",
        "ALTER TABLE purchase_items ADD COLUMN variant TEXT",
        "ALTER TABLE purchase_items ADD COLUMN model_number TEXT",
        "ALTER TABLE purchase_items ADD COLUMN serial_number TEXT",
        "ALTER TABLE purchase_items ADD COLUMN warranty_period TEXT",
        "ALTER TABLE purchase_items ADD COLUMN warranty_expiry DATE",
        "ALTER TABLE categories ADD COLUMN product_type TEXT DEFAULT 'medicine'",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* already applied */ }
    }

    // Categories are kept separate per product type (Medicine / Cosmetics / Equipment).
    // Backfill legacy categories by name, then seed the standard per-type lists.
    $pdo->exec("UPDATE categories SET product_type='cosmetic' WHERE LOWER(name) LIKE '%cosmetic%'");
    $pdo->exec("UPDATE categories SET product_type='equipment' WHERE LOWER(name) LIKE '%equipment%' OR LOWER(name) LIKE '%device%'");
    $typeCat = $pdo->prepare("INSERT OR IGNORE INTO categories (name, product_type) VALUES (?,?)");
    foreach (['Antibiotic', 'Analgesic', 'Antifungal', 'Antihypertensive', 'Antidiabetic', 'Vitamins & Supplements'] as $n) {
        $typeCat->execute([$n, 'medicine']);
    }
    foreach (['Skincare', 'Haircare', 'Makeup', 'Body Care', 'Sunscreen', 'Cleansing'] as $n) {
        $typeCat->execute([$n, 'cosmetic']);
    }
    foreach (['Diagnostic Equipment', 'Pharmacy Equipment', 'Medical Equipment', 'Storage Equipment'] as $n) {
        $typeCat->execute([$n, 'equipment']);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_id INTEGER NOT NULL,
            supplier_id INTEGER,
            payment_date DATE NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL DEFAULT 'cash',
            reference_number TEXT,
            notes TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_id) REFERENCES purchases(id),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS purchase_returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            return_number TEXT NOT NULL UNIQUE,
            purchase_id INTEGER NOT NULL,
            supplier_id INTEGER,
            return_date DATE NOT NULL,
            total_amount REAL NOT NULL DEFAULT 0,
            reason TEXT,
            status TEXT DEFAULT 'completed',
            created_by INTEGER,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_id) REFERENCES purchases(id),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS purchase_return_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            return_id INTEGER NOT NULL,
            purchase_item_id INTEGER,
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            quantity INTEGER NOT NULL DEFAULT 0,
            unit_price REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            FOREIGN KEY (return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id),
            FOREIGN KEY (batch_id) REFERENCES batches(id)
        );
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_supplier ON purchases(supplier_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_due ON purchases(due_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_pay_status ON purchases(payment_status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchase_payments_purchase ON purchase_payments(purchase_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchase_returns_purchase ON purchase_returns(purchase_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suppliers_code ON suppliers(supplier_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id)");

    $pdo->exec("
        UPDATE purchases
        SET purchase_date = COALESCE(purchase_date, date(created_at)),
            grand_total = CASE WHEN COALESCE(grand_total, 0) > 0 THEN grand_total ELSE COALESCE(total_amount, 0) END,
            subtotal = CASE WHEN COALESCE(subtotal, 0) > 0 THEN subtotal ELSE COALESCE(total_amount, 0) END,
            total_paid = CASE
                WHEN COALESCE(payment_status, '') IN ('unpaid', 'partial', 'paid') AND COALESCE(total_paid, 0) > 0 THEN total_paid
                WHEN COALESCE(grand_total, total_amount, 0) > 0 AND COALESCE(total_paid, 0) = 0 AND COALESCE(status, 'received') = 'received'
                     AND COALESCE(payment_status, 'paid') IN ('paid', '') THEN COALESCE(grand_total, total_amount, 0)
                ELSE COALESCE(total_paid, 0)
            END
        WHERE purchase_date IS NULL OR COALESCE(grand_total, 0) = 0
    ");

    $legacy = $pdo->query("SELECT id, reference, created_at FROM purchases WHERE purchase_number IS NULL OR TRIM(purchase_number) = ''")->fetchAll();
    $updNum = $pdo->prepare("UPDATE purchases SET purchase_number = ? WHERE id = ?");
    foreach ($legacy as $row) {
        $year = date('Y', strtotime($row['created_at'] ?: 'now'));
        $updNum->execute([sprintf('PUR-%s-%06d', $year, (int)$row['id']), (int)$row['id']]);
    }

    $backfillFlag = $pdo->query("SELECT value FROM settings WHERE key='purchase_ap_backfilled'")->fetchColumn();
    if ($backfillFlag !== '1') {
        $pdo->exec("
            UPDATE purchases SET
                grand_total = CASE WHEN COALESCE(grand_total,0) > 0 THEN grand_total ELSE COALESCE(total_amount,0) END,
                subtotal = CASE WHEN COALESCE(subtotal,0) > 0 THEN subtotal ELSE COALESCE(total_amount,0) END,
                purchase_date = COALESCE(purchase_date, date(created_at)),
                status = COALESCE(NULLIF(status,''), 'received'),
                total_paid = CASE WHEN COALESCE(total_paid,0) > 0 THEN total_paid ELSE COALESCE(NULLIF(grand_total,0), total_amount, 0) END,
                total_due = 0,
                payment_status = 'paid'
            WHERE id NOT IN (SELECT purchase_id FROM purchase_payments)
              AND COALESCE(total_paid, 0) = 0
              AND COALESCE(status, 'received') IN ('received', '')
        ");
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('purchase_ap_backfilled', '1')")->execute();
    }

    $pdo->exec("
        UPDATE purchase_items
        SET line_total = CASE WHEN COALESCE(line_total, 0) > 0 THEN line_total ELSE quantity * purchase_price END
        WHERE COALESCE(line_total, 0) = 0
    ");

    $orphans = $pdo->query("SELECT id FROM suppliers WHERE supplier_code IS NULL OR TRIM(supplier_code) = ''")->fetchAll(PDO::FETCH_COLUMN);
    $updSup = $pdo->prepare("UPDATE suppliers SET supplier_code = ?, status = COALESCE(NULLIF(status, ''), 'active') WHERE id = ?");
    foreach ($orphans as $sid) {
        $updSup->execute([sprintf('SUP-%06d', (int)$sid), (int)$sid]);
    }

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['purchase_require_approval', '0']);
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_purchases_number ON purchases(purchase_number)");
    } catch (PDOException $e) { /* duplicates until backfill */ }
}

function settingsCache(bool $reset = false): array {
    static $cache = null;
    if ($reset) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = [];
        foreach (getDB()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function getSetting(string $key, string $default = ''): string {
    $cache = settingsCache();
    return $cache[$key] ?? $default;
}

function clearSettingsCache(): void {
    settingsCache(true);
}

function generateInvoice(): string {
    return 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function currency(float $amount): string {
    static $cur = null;
    if ($cur === null) $cur = getSetting('currency', 'ETB');
    return $cur . ' ' . number_format($amount, 2);
}

function businessToday(): string {
    return date('Y-m-d');
}

function businessDayUtcRange(?string $localDate = null): array {
    $tz = new DateTimeZone('Africa/Addis_Ababa');
    $utc = new DateTimeZone('UTC');
    $localDate = $localDate ?? businessToday();
    $start = new DateTime($localDate . ' 00:00:00', $tz);
    $end = (clone $start)->modify('+1 day');
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function businessMonthUtcRange(): array {
    $tz = new DateTimeZone('Africa/Addis_Ababa');
    $utc = new DateTimeZone('UTC');
    $start = new DateTime(date('Y-m-01') . ' 00:00:00', $tz);
    $end = new DateTime(businessToday() . ' 00:00:00', $tz);
    $end->modify('+1 day');
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function flashSet(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function renderPagination(int $page, int $totalPages, string $baseUrl): void {
    if ($totalPages <= 1) return;
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    echo '<div class="pagination">';
    if ($page > 1) {
        echo '<a class="btn btn-ghost btn-sm" href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1)) . '">Prev</a>';
    }
    echo '<span class="pagination-info">Page ' . $page . ' of ' . $totalPages . '</span>';
    if ($page < $totalPages) {
        echo '<a class="btn btn-ghost btn-sm" href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1)) . '">Next</a>';
    }
    echo '</div>';
}
?>
