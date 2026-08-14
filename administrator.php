<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/permissions_lib.php';

requireAnyPermission(['users.manage', 'roles.manage']);

$pdo = getDB();
$currentUser = currentUser();
$tab = $_GET['tab'] ?? (can('roles.manage') ? 'roles' : 'users');
if ($tab === 'roles' && !can('roles.manage')) {
    $tab = 'users';
}
if ($tab === 'users' && !can('users.manage')) {
    $tab = 'roles';
}
$editRoleId = (int) ($_GET['edit_role'] ?? 0);
$editUserId = (int) ($_GET['edit_user'] ?? 0);
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
          <input type="text" name="name" placeholder="e.g. Pharmacist, Cashier" required>
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

<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
