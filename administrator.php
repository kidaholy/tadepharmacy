<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/permissions_lib.php';
require_once __DIR__ . '/sales_lib.php';

requireAnyPermission(['users.manage', 'roles.manage', 'categories.manage']);

$pdo = getDB();
$currentUser = currentUser();
$tab = $_GET['tab'] ?? (can('roles.manage') ? 'roles' : (can('users.manage') ? 'users' : 'categories'));
if ($tab === 'roles' && !can('roles.manage')) {
    $tab = can('users.manage') ? 'users' : (can('categories.manage') ? 'categories' : 'audit');
}
if ($tab === 'users' && !can('users.manage')) {
    $tab = can('roles.manage') ? 'roles' : (can('categories.manage') ? 'categories' : 'audit');
}
if ($tab === 'categories' && !can('categories.manage')) {
    $tab = can('roles.manage') ? 'roles' : (can('users.manage') ? 'users' : 'audit');
}
if ($tab === 'audit' && !can('roles.manage') && !can('users.manage')) {
    $tab = can('categories.manage') ? 'categories' : (can('roles.manage') ? 'roles' : 'users');
}
$editRoleId = (int) ($_GET['edit_role'] ?? 0);
$editUserId = (int) ($_GET['edit_user'] ?? 0);
$editCategoryId = (int) ($_GET['edit_category'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'create_role' && can('roles.manage')) {
        $result = createRole($pdo, $_POST['name'] ?? '', $_POST['description'] ?? '');
        if ($result['ok']) {
            flashSet('success', 'Role created. Configure its permissions below.');
            header('Location: administrator.php?tab=roles&edit_role=' . $result['id']);
            exit;
        }
        $error = $result['error'];
        $tab = 'roles';
    }

    if ($act === 'update_role' && can('roles.manage')) {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $result = updateRole($pdo, $roleId, $_POST['name'] ?? '', $_POST['description'] ?? '');
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $permInput = $_POST['perm'] ?? [];
            $resolved = [];
            foreach (allPermissionKeys() as $key) {
                $effect = $permInput[$key] ?? 'ignore';
                if ($effect === 'allow' || $effect === 'deny') {
                    $resolved[$key] = $effect;
                }
            }
            setRolePermissions($pdo, $roleId, $resolved);
            flashSet('success', 'Role and permissions saved successfully.');
            header('Location: administrator.php?tab=roles&edit_role=' . $roleId);
            exit;
        }
        $tab = 'roles';
        $editRoleId = $roleId;
    }

    if ($act === 'delete_role' && can('roles.manage')) {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $result = deleteRole($pdo, $roleId);
        flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Role deleted.' : $result['error']);
        header('Location: administrator.php?tab=roles');
        exit;
    }

    if ($act === 'create_user' && can('users.manage')) {
        $result = createUser(
            $pdo,
            $_POST['username'] ?? '',
            $_POST['password'] ?? '',
            $_POST['full_name'] ?? '',
            (int) ($_POST['role_id'] ?? 0)
        );
        flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? 'User created successfully.' : $result['error']);
        header('Location: administrator.php?tab=users');
        exit;
    }

    if ($act === 'update_user' && can('users.manage')) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $result = updateUser(
            $pdo,
            $userId,
            $_POST['username'] ?? '',
            $_POST['full_name'] ?? '',
            (int) ($_POST['role_id'] ?? 0),
            trim($_POST['password'] ?? '')
        );
        if ($result['ok'] && $userId === $currentUser['id']) {
            refreshUserSession($userId);
        }
        flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? 'User updated successfully.' : $result['error']);
        header('Location: administrator.php?tab=users' . ($result['ok'] ? '&edit_user=' . $userId : '&edit_user=' . $userId));
        exit;
    }

    if ($act === 'delete_user' && can('users.manage')) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $result = deleteUser($pdo, $userId, $currentUser['id']);
        flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? 'User deleted.' : $result['error']);
        header('Location: administrator.php?tab=users');
        exit;
    }

    if ($act === 'create_category' && can('categories.manage')) {
        require_once __DIR__ . '/inventory_lib.php';
        $name = canonicalizeCategoryName(trim($_POST['name'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $productType = trim($_POST['product_type'] ?? 'medicine');
        if (!isset(productTypes()[$productType])) {
            $productType = 'medicine';
        }
        
        if ($name === '') {
            $error = 'Category name is required.';
            $tab = 'categories';
        } else {
            $exists = $pdo->prepare('SELECT id, product_type FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $exists->execute([$name]);
            $existing = $exists->fetch();
            if ($existing && ($existing['product_type'] ?? 'medicine') === $productType) {
                $error = 'A category with this name already exists for ' . strtolower(productTypes()[$productType]) . '.';
                $tab = 'categories';
            } elseif ($existing) {
                $error = 'Category "' . $name . '" already exists under ' . strtolower(productTypes()[$existing['product_type'] ?? 'medicine']) . '. Choose a different name.';
                $tab = 'categories';
            } else {
                $pdo->prepare('INSERT INTO categories (name, description, product_type) VALUES (?, ?, ?)')
                    ->execute([$name, $description, $productType]);
                flashSet('success', 'Category created successfully.');
                header('Location: administrator.php?tab=categories&type=' . urlencode($productType));
                exit;
            }
        }
    }

    if ($act === 'update_category' && can('categories.manage')) {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $productType = trim($_POST['product_type'] ?? 'medicine');
        if (!isset(productTypes()[$productType])) {
            $productType = 'medicine';
        }
        
        if ($name === '') {
            $error = 'Category name is required.';
            $tab = 'categories';
            $editCategoryId = $categoryId;
        } else {
            $exists = $pdo->prepare('SELECT id, product_type FROM categories WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1');
            $exists->execute([$name, $categoryId]);
            $existing = $exists->fetch();
            if ($existing && ($existing['product_type'] ?? 'medicine') === $productType) {
                $error = 'A category with this name already exists for ' . strtolower(productTypes()[$productType]) . '.';
                $tab = 'categories';
                $editCategoryId = $categoryId;
            } elseif ($existing) {
                $error = 'Category "' . $name . '" already exists under ' . strtolower(productTypes()[$existing['product_type'] ?? 'medicine']) . '. Choose a different name.';
                $tab = 'categories';
                $editCategoryId = $categoryId;
            } else {
                $pdo->prepare('UPDATE categories SET name = ?, description = ?, product_type = ? WHERE id = ?')
                    ->execute([$name, $description, $productType, $categoryId]);
                flashSet('success', 'Category updated successfully.');
                header('Location: administrator.php?tab=categories&type=' . urlencode($productType));
                exit;
            }
        }
    }

    if ($act === 'delete_category' && can('categories.manage')) {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $moveTo = (int) ($_POST['move_to'] ?? 0);
        
        $catRow = $pdo->prepare('SELECT product_type FROM categories WHERE id = ?');
        $catRow->execute([$categoryId]);
        $deletedType = $catRow->fetchColumn() ?: 'medicine';
        
        // Check if category has medicines
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM medicines WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        $medCount = (int) $stmt->fetchColumn();
        
        if ($medCount > 0 && $moveTo === 0) {
            $error = 'This category has ' . $medCount . ' medicine(s). Please select a category to move them to before deleting.';
            $tab = 'categories';
            $editCategoryId = $categoryId;
        } else {
            // Move medicines if needed
            if ($medCount > 0 && $moveTo > 0) {
                $pdo->prepare('UPDATE medicines SET category_id = ? WHERE category_id = ?')
                    ->execute([$moveTo, $categoryId]);
            }
            
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
            flashSet('success', 'Category deleted' . ($medCount > 0 ? " ({$medCount} medicine(s) moved)" : '') . '.');
            header('Location: administrator.php?tab=categories&type=' . urlencode($deletedType));
            exit;
        }
    }
}

$roles = getAllRoles($pdo);
$users = getAllUsers($pdo);
$editRole = $editRoleId ? getRoleById($pdo, $editRoleId) : null;
$editRolePerms = $editRole ? getRolePermissions($pdo, $editRoleId) : [];
$editUser = $editUserId ? null : null;
if ($editUserId) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $editUserId) {
            $editUser = $u;
            break;
        }
    }
}

// Categories data - all categories with counts
$allCategories = $pdo->query('
    SELECT c.*, COUNT(m.id) AS medicine_count
    FROM categories c
    LEFT JOIN medicines m ON m.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name COLLATE NOCASE
')->fetchAll();

$categoriesByType = ['medicine' => [], 'cosmetic' => [], 'equipment' => []];
foreach ($allCategories as $c) {
    $pt = $c['product_type'] ?? 'medicine';
    if (isset($categoriesByType[$pt])) {
        $categoriesByType[$pt][] = $c;
    }
}

// Item counts per product type (matches Medicines page tabs)
$typeCounts = ['medicine' => 0, 'cosmetic' => 0, 'equipment' => 0];
foreach ($pdo->query("SELECT COALESCE(product_type,'medicine') AS t, COUNT(*) AS c FROM medicines GROUP BY t") as $row) {
    if (isset($typeCounts[$row['t']])) {
        $typeCounts[$row['t']] = (int) $row['c'];
    }
}

$catType = trim($_GET['type'] ?? $_GET['cat_type'] ?? 'medicine');
if (!isset(productTypes()[$catType])) {
    $catType = 'medicine';
}

$typeCategories = $categoriesByType[$catType] ?? [];
$categories = categoryDetailsForType($typeCategories, $catType);
$hiddenCategories = array_values(array_filter($typeCategories, function ($c) use ($categories) {
    $ids = array_map(fn($x) => (int) $x['id'], $categories);
    return !in_array((int) $c['id'], $ids, true);
}));
$typeMeta = productTypeMeta()[$catType];

$editCategory = null;
if ($editCategoryId) {
    foreach ($typeCategories as $c) {
        if ((int) $c['id'] === $editCategoryId) {
            $editCategory = $c;
            break;
        }
    }
}

$catalog = permissionCatalog();

renderHead('Administrator');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Administrator', 'Users, roles & privilege management'); ?>
<div class="page-body">

<?php if ($error): ?>
<div class="alert alert-danger"><i data-lucide="alert-circle"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php $flash = flashGet(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> auto-hide">
  <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<div class="admin-tabs">
  <?php if (can('roles.manage')): ?>
  <a href="administrator.php?tab=roles" class="admin-tab<?= $tab === 'roles' ? ' active' : '' ?>">
    <i data-lucide="shield"></i> Roles & Permissions
  </a>
  <?php endif; ?>
  <?php if (can('users.manage')): ?>
  <a href="administrator.php?tab=users" class="admin-tab<?= $tab === 'users' ? ' active' : '' ?>">
    <i data-lucide="users"></i> Users
  </a>
  <?php endif; ?>
  <?php if (can('categories.manage')): ?>
  <a href="administrator.php?tab=categories" class="admin-tab<?= $tab === 'categories' ? ' active' : '' ?>">
    <i data-lucide="folder"></i> Categories
  </a>
  <?php endif; ?>
  <?php if (can('roles.manage') || can('users.manage')): ?>
  <a href="administrator.php?tab=audit" class="admin-tab<?= $tab === 'audit' ? ' active' : '' ?>">
    <i data-lucide="scroll-text"></i> Audit Trail
  </a>
  <?php endif; ?>
</div>

<?php if ($tab === 'roles' && can('roles.manage')): ?>

<div class="grid-2 admin-grid">
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><span class="card-title">Create Custom Role</span></div>
      <form method="POST">
        <input type="hidden" name="act" value="create_role">
        <div class="form-group">
          <label>Role Name</label>
          <input type="text" name="name" placeholder="e.g. Senior Pharmacist, Night Cashier" required>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="2" placeholder="Optional description"></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i data-lucide="plus"></i> Create Role</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">All Roles</span></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Role</th>
              <th>Users</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $role): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($role['name']) ?></strong>
                <?php if ((int) $role['is_system'] === 1): ?>
                  <span class="badge badge-blue" style="margin-left:6px;">System</span>
                <?php endif; ?>
                <?php if ($role['description']): ?>
                  <div style="font-size:12px;color:var(--text-300);margin-top:4px;"><?= htmlspecialchars($role['description']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-gray"><?= (int) $role['user_count'] ?></span></td>
              <td style="text-align:right;white-space:nowrap;">
                <a href="administrator.php?tab=roles&edit_role=<?= (int) $role['id'] ?>" class="btn btn-ghost btn-sm">
                  <i data-lucide="settings-2"></i> Permissions
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <?php if ($editRole): ?>
    <div class="card-header">
      <span class="card-title">Privilege Management — <?= htmlspecialchars($editRole['name']) ?></span>
    </div>
    <p style="font-size:13px;color:var(--text-300);margin-bottom:16px;">
      Set each permission to <strong>Allow</strong>, <strong>Deny</strong>, or <strong>Ignore</strong>.
      Ignored permissions are not granted. Deny overrides Allow.
    </p>
    <form method="POST">
      <input type="hidden" name="act" value="update_role">
      <input type="hidden" name="role_id" value="<?= (int) $editRole['id'] ?>">
      <div class="form-group">
        <label>Role Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editRole['name']) ?>" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="2"><?= htmlspecialchars($editRole['description'] ?? '') ?></textarea>
      </div>

      <div class="perm-matrix-wrap">
        <table class="perm-matrix">
          <thead>
            <tr>
              <th>Module / Permission</th>
              <th class="perm-col">Allow</th>
              <th class="perm-col">Deny</th>
              <th class="perm-col">Ignore</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($catalog as $module => $perms): ?>
            <tr class="perm-module-row">
              <td colspan="4"><?= htmlspecialchars($module) ?></td>
            </tr>
            <?php foreach ($perms as $key => $label):
              $current = $editRolePerms[$key] ?? 'ignore';
            ?>
            <tr>
              <td>
                <span class="perm-label"><?= htmlspecialchars($label) ?></span>
                <span class="perm-key"><?= htmlspecialchars($key) ?></span>
              </td>
              <?php foreach (['allow', 'deny', 'ignore'] as $effect): ?>
              <td class="perm-col">
                <label class="perm-radio">
                  <input type="radio" name="perm[<?= htmlspecialchars($key) ?>]" value="<?= $effect ?>" <?= $current === $effect ? 'checked' : '' ?>>
                </label>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Role & Permissions</button>
      </div>
    </form>
    <?php if ((int) $editRole['is_system'] !== 1): ?>
    <form method="POST" onsubmit="return confirm('Delete this role permanently?')" style="margin-top:10px;">
      <input type="hidden" name="act" value="delete_role">
      <input type="hidden" name="role_id" value="<?= (int) $editRole['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i> Delete Role</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <div class="card-header"><span class="card-title">Privilege Management</span></div>
    <div class="empty-state" style="padding:40px 20px;text-align:center;">
      <i data-lucide="shield" style="width:48px;height:48px;color:var(--text-300);margin-bottom:12px;"></i>
      <p style="color:var(--text-300);font-size:14px;">Select a role from the list to configure allow, deny, and ignore permissions.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'users' && can('users.manage')): ?>

<div style="margin-bottom:16px;">
  <a href="administrator.php?tab=users&edit_user=<?= (int) $currentUser['id'] ?>" class="btn btn-ghost btn-sm">
    <i data-lucide="user-cog"></i> Edit My Account
  </a>
</div>

<div class="grid-2 admin-grid">
  <div class="card">
    <div class="card-header">
      <span class="card-title">
        <?= $editUser ? 'Edit User' : 'Create User' ?>
        <?php if ($editUser && (int) $editUser['id'] === $currentUser['id']): ?>
          <span class="badge badge-blue" style="margin-left:8px;">Your account</span>
        <?php endif; ?>
      </span>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="<?= $editUser ? 'update_user' : 'create_user' ?>">
      <?php if ($editUser): ?>
      <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username"
          value="<?= htmlspecialchars($editUser['username'] ?? '') ?>"
          required autocomplete="off">
      </div>

      <div class="form-group">
        <label>
          <?= $editUser ? 'New Password' : 'Password' ?>
          <?php if ($editUser): ?>
          <span style="color:var(--text-300);font-weight:400;text-transform:none;font-size:11px;">(leave blank to keep current)</span>
          <?php endif; ?>
        </label>
        <input type="password" name="password" <?= $editUser ? '' : 'required' ?> autocomplete="new-password">
      </div>

      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="role_id" required>
          <?php foreach ($roles as $role): ?>
          <option value="<?= (int) $role['id'] ?>"
            <?= $editUser && (int) $editUser['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($role['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary">
          <i data-lucide="<?= $editUser ? 'save' : 'user-plus' ?>"></i>
          <?= $editUser ? 'Update User' : 'Create User' ?>
        </button>
        <?php if ($editUser): ?>
        <a href="administrator.php?tab=users" class="btn btn-ghost">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">All Users</span></div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Role</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
          <tr<?= (int) $user['id'] === $currentUser['id'] ? ' style="background:var(--accent-glow);"' : '' ?>>
            <td>
              <?= htmlspecialchars($user['full_name']) ?>
              <?php if ((int) $user['id'] === $currentUser['id']): ?>
                <span class="badge badge-green" style="margin-left:6px;">You</span>
              <?php endif; ?>
            </td>
            <td><code style="font-size:12px;"><?= htmlspecialchars($user['username']) ?></code></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($user['role_name'] ?? ucfirst($user['role'])) ?></span></td>
            <td style="text-align:right;white-space:nowrap;">
              <a href="administrator.php?tab=users&edit_user=<?= (int) $user['id'] ?>" class="btn btn-ghost btn-sm">
                <i data-lucide="edit"></i> Edit
              </a>
              <?php if ((int) $user['id'] !== $currentUser['id']): ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?')">
                <input type="hidden" name="act" value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'categories' && can('categories.manage')): ?>

<?php
$catPlaceholders = [
    'medicine'  => 'e.g. Antibiotics & Antimicrobials, Pain Relief',
    'cosmetic'  => 'e.g. Cosmetics - Face Care, Cosmetics - Body Care',
    'equipment' => 'e.g. Diagnostic Equipment, Pharmacy Equipment',
];
$catPlaceholder = $catPlaceholders[$catType] ?? 'e.g. Category name';
?>

<?php renderTypeTabs('administrator.php', $catType, $typeCounts, ['tab' => 'categories']); ?>

<p style="font-size:13px;color:var(--text-300);margin:-8px 0 16px;">
  Manage <?= strtolower($typeMeta['title']) ?> categories — the same list used when adding or filtering <?= strtolower($typeMeta['plural']) ?> in the catalogue.
</p>

<div class="grid-2 admin-grid">
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><span class="card-title">Create <?= htmlspecialchars($typeMeta['title']) ?> Category</span></div>
      <form method="POST">
        <input type="hidden" name="act" value="create_category">
        <input type="hidden" name="product_type" value="<?= htmlspecialchars($catType) ?>">
        <div class="form-group">
          <label>Category Name *</label>
          <input type="text" name="name" placeholder="<?= htmlspecialchars($catPlaceholder) ?>" required>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="2" placeholder="Optional description"></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i data-lucide="plus"></i> Create Category</button>
        </div>
      </form>
    </div>

    <?php if ($editCategory): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">Delete Category</span></div>
      <p style="font-size:13px;color:var(--text-300);margin-bottom:16px;">
        <?php if ((int) $editCategory['medicine_count'] > 0): ?>
          This category has <strong><?= (int) $editCategory['medicine_count'] ?></strong> item(s). You must move them to another category before deleting.
        <?php else: ?>
          This category is empty. You can safely delete it.
        <?php endif; ?>
      </p>
      <form method="POST" onsubmit="return confirm('Delete this category permanently?')">
        <input type="hidden" name="act" value="delete_category">
        <input type="hidden" name="category_id" value="<?= (int) $editCategory['id'] ?>">
        <?php if ((int) $editCategory['medicine_count'] > 0): ?>
        <div class="form-group">
          <label>Move items to</label>
          <select name="move_to" required>
            <option value="0">— Select a category —</option>
            <?php foreach ($typeCategories as $c):
              if ((int) $c['id'] !== (int) $editCategory['id']): ?>
            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endif; endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i> Delete Category</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if ($editCategory): ?>
    <div class="card-header">
      <span class="card-title">Edit Category — <?= htmlspecialchars($editCategory['name']) ?></span>
    </div>
    <form method="POST">
      <input type="hidden" name="act" value="update_category">
      <input type="hidden" name="category_id" value="<?= (int) $editCategory['id'] ?>">
      <input type="hidden" name="product_type" value="<?= htmlspecialchars($catType) ?>">
      <div class="form-group">
        <label>Product Type</label>
        <input type="text" value="<?= htmlspecialchars($typeMeta['title']) ?>" disabled>
      </div>
      <div class="form-group">
        <label>Category Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editCategory['name']) ?>" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="2"><?= htmlspecialchars($editCategory['description'] ?? '') ?></textarea>
      </div>
      <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Category</button>
        <a href="administrator.php?tab=categories&type=<?= htmlspecialchars($catType) ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
    <?php else: ?>
    <div class="card-header"><span class="card-title">Edit Category</span></div>
    <div class="empty-state" style="padding:40px 20px;text-align:center;">
      <i data-lucide="folder" style="width:48px;height:48px;color:var(--text-300);margin-bottom:12px;"></i>
      <p style="color:var(--text-300);font-size:14px;">Select a category from the list to edit its name or description.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:20px;">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($typeMeta['plural']) ?> Categories (<?= count($categories) ?>)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Category Name</th>
          <th>Description</th>
          <th>Items</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
        <tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-300);">No <?= strtolower($typeMeta['title']) ?> categories yet. Create one above!</td></tr>
        <?php else: ?>
        <?php foreach ($categories as $cat): ?>
        <tr<?= (int) $cat['id'] === $editCategoryId ? ' style="background:var(--accent-glow);"' : '' ?>>
          <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
          <td style="color:var(--text-300);font-size:13px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($cat['description'] ?? '—') ?>
          </td>
          <td><span class="badge badge-gray"><?= (int) $cat['medicine_count'] ?></span></td>
          <td style="text-align:right;white-space:nowrap;">
            <a href="administrator.php?tab=categories&type=<?= htmlspecialchars($catType) ?>&edit_category=<?= (int) $cat['id'] ?>" class="btn btn-ghost btn-sm">
              <i data-lucide="edit"></i> Edit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($hiddenCategories): ?>
<div class="card" style="margin-top:20px;">
  <div class="card-header">
    <span class="card-title">Catalogue Buckets (<?= count($hiddenCategories) ?>)</span>
  </div>
  <p style="font-size:13px;color:var(--text-300);padding:0 16px 12px;margin:0;">
    Generic groupings hidden from the <?= strtolower($typeMeta['plural']) ?> filter chips but still used by some items.
  </p>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Category Name</th>
          <th>Description</th>
          <th>Items</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hiddenCategories as $cat): ?>
        <tr<?= (int) $cat['id'] === $editCategoryId ? ' style="background:var(--accent-glow);"' : '' ?>>
          <td><strong><?= htmlspecialchars($cat['name']) ?></strong> <span class="badge badge-gray" style="margin-left:6px;">bucket</span></td>
          <td style="color:var(--text-300);font-size:13px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($cat['description'] ?? '—') ?>
          </td>
          <td><span class="badge badge-gray"><?= (int) $cat['medicine_count'] ?></span></td>
          <td style="text-align:right;white-space:nowrap;">
            <a href="administrator.php?tab=categories&type=<?= htmlspecialchars($catType) ?>&edit_category=<?= (int) $cat['id'] ?>" class="btn btn-ghost btn-sm">
              <i data-lucide="edit"></i> Edit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'audit' && (can('roles.manage') || can('users.manage'))): ?>
<?php
$auditQ = trim($_GET['aq'] ?? '');
$auditPage = max(1, (int)($_GET['apage'] ?? 1));
$auditPer = 50;
$auditWhere = [];
$auditParams = [];
if ($auditQ !== '') {
    $auditWhere[] = "(a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $like = '%' . $auditQ . '%';
    $auditParams = [$like, $like, $like, $like, $like];
}
$auditWhereSql = $auditWhere ? ('WHERE ' . implode(' AND ', $auditWhere)) : '';
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $auditWhereSql");
$cntStmt->execute($auditParams);
$auditTotal = (int)$cntStmt->fetchColumn();
$auditPages = max(1, (int)ceil($auditTotal / $auditPer));
if ($auditPage > $auditPages) $auditPage = $auditPages;
$auditOffset = ($auditPage - 1) * $auditPer;
$auditStmt = $pdo->prepare("
    SELECT a.*, u.full_name, u.username
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    $auditWhereSql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT $auditPer OFFSET $auditOffset
");
$auditStmt->execute($auditParams);
$auditRows = $auditStmt->fetchAll();
$auditBase = 'administrator.php?' . http_build_query(array_filter(['tab' => 'audit', 'aq' => $auditQ ?: null]));
?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Audit Trail (<?= number_format($auditTotal) ?>)</span>
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="tab" value="audit">
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="aq" value="<?= htmlspecialchars($auditQ) ?>" placeholder="Search user, action, details..."></div>
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date / Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$auditRows): ?>
        <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-300);">No audit entries found</td></tr>
      <?php else: foreach ($auditRows as $a): ?>
        <tr>
          <td style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars(date('M j, Y H:i', strtotime($a['created_at']))) ?></td>
          <td><?= htmlspecialchars($a['full_name'] ?: ($a['username'] ?: 'System')) ?></td>
          <td><code style="font-size:12px;"><?= htmlspecialchars($a['action']) ?></code></td>
          <td style="font-size:12px;"><?= htmlspecialchars($a['entity_type']) ?><?= $a['entity_id'] ? (' #' . (int)$a['entity_id']) : '' ?></td>
          <td style="font-size:12px;color:var(--text-300);max-width:420px;"><?= htmlspecialchars($a['details'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($auditPage, $auditPages, $auditBase); ?>
</div>

<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
