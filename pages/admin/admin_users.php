<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_shell.php';
require_role(ROLE_ADMIN);

$conn = get_whpokayoke_connection();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = $editId ? fetch_one($conn, 'SELECT * FROM AppUsers WHERE UserID = ?', [$editId]) : null;
$users = fetch_all($conn, 'SELECT * FROM AppUsers ORDER BY RoleName, Username');

$currentUser = current_user();
$currentRole = strtolower($currentUser['role'] ?? '');
$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;
foreach ($users as $userRow) {
    if ((int)($userRow['IsActive'] ?? 0) === 1) {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <title>Admin - Users</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app-shell.css" rel="stylesheet">

    <style>
        :root {
            --sap-shell: #354a5f;
            --sap-shell-dark: #233545;
            --sap-accent: #0a6ed1;
            --sap-accent-hover: #085caf;
            --sap-highlight: #d1e8ff;
            --sap-bg: #f7f7f7;
            --sap-card: #ffffff;
            --sap-border: #d9d9d9;
            --sap-border-soft: #ebebeb;
            --sap-text: #32363a;
            --sap-muted: #6a6d70;
            --sap-success-bg: #f1fdf6;
            --sap-success: #107e3e;
            --sap-error-bg: #fff1f1;
            --sap-error: #bb0000;
            --side-width: 17rem;
            --topbar-height: 3.25rem;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--sap-bg);
            color: var(--sap-text);
            font-family: "72", "Segoe UI", Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        .sap-shellbar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 1040;
            min-height: var(--topbar-height);
            background: linear-gradient(90deg, var(--sap-shell-dark), var(--sap-shell));
            color: #fff;
            display: flex;
            align-items: center;
            gap: .85rem;
            padding: .45rem 1rem;
            box-shadow: 0 .125rem .5rem rgba(0, 0, 0, .22);
        }

        .shell-menu-btn {
            display: none;
            border: 0;
            background: rgba(255, 255, 255, .12);
            color: #fff;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .375rem;
            font-size: 1.25rem;
            line-height: 1;
        }

        .shell-logo {
            width: 2.4rem;
            height: 2.4rem;
            border-radius: .35rem;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 auto;
            padding: .15rem;
            margin-right: .25rem;
        }

        .shell-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .shell-title-wrap {
            min-width: 0;
            flex: 1;
        }

        .shell-title {
            font-size: .98rem;
            font-weight: 800;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .shell-subtitle {
            font-size: .74rem;
            color: rgba(255, 255, 255, .85);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-layout {
            min-height: 100vh;
            padding-top: var(--topbar-height);
        }

        .sap-side-nav {
            position: fixed;
            inset: var(--topbar-height) auto 0 0;
            width: var(--side-width);
            z-index: 1035;
            background: #fff;
            border-right: 1px solid var(--sap-border);
            display: flex;
            flex-direction: column;
            box-shadow: .125rem 0 .5rem rgba(0, 0, 0, .08);
        }

        .side-nav-header {
            padding: 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fff;
        }

        .side-nav-eyebrow {
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .side-nav-title {
            margin-top: .25rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--sap-text);
        }

        .sap-nav-menu {
            flex: 1;
            overflow-y: auto;
            padding: .5rem;
        }

        .sap-nav-section {
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: .875rem .625rem .375rem;
            letter-spacing: .045em;
        }

        .sap-nav-link {
            min-height: 2.5rem;
            display: flex;
            align-items: center;
            gap: .625rem;
            color: var(--sap-text);
            text-decoration: none;
            border-radius: .375rem;
            padding: .5rem .625rem;
            font-size: .875rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .sap-nav-link:hover {
            background: #f5f6f7;
            border-color: #e5e5e5;
            color: var(--sap-accent);
        }

        .sap-nav-link.active {
            background: var(--sap-highlight);
            border-color: #8fc7ff;
            color: #074f91;
        }

        .sap-nav-icon {
            width: 1.375rem;
            text-align: center;
            font-size: 1rem;
            flex: 0 0 auto;
        }

        .sap-side-footer {
            padding: .75rem;
            border-top: 1px solid var(--sap-border-soft);
            background: #fbfbfb;
        }

        .side-user-card {
            display: flex;
            align-items: center;
            gap: .625rem;
            padding: .625rem;
            margin-bottom: .625rem;
            border: 1px solid var(--sap-border-soft);
            background: #fff;
            border-radius: .5rem;
            min-width: 0;
        }

        .side-user-avatar {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: #91c8f6;
            color: #0b2948;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .side-user-details {
            min-width: 0;
            flex: 1;
        }

        .side-user-name,
        .side-user-role {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .side-user-name {
            color: var(--sap-text);
            font-size: .875rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .side-user-role {
            margin-top: .125rem;
            color: var(--sap-muted);
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .logout-link {
            width: 100%;
            min-height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ffb8b8;
            color: var(--sap-error);
            background: #fff;
            text-decoration: none;
            border-radius: .375rem;
            font-size: .875rem;
            font-weight: 700;
        }

        .logout-link:hover {
            background: var(--sap-error-bg);
            color: var(--sap-error);
        }

        .main-content {
            margin-left: var(--side-width);
            min-height: calc(100vh - var(--topbar-height));
        }

        .sap-page-header {
            background: linear-gradient(180deg, #eff6ff 0%, #f7f7f7 100%);
            border-bottom: 1px solid var(--sap-border-soft);
            padding: 1.25rem 1.5rem 1rem;
        }

        .page-kicker {
            color: var(--sap-muted);
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: .25rem;
        }

        .page-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .page-title {
            margin: 0;
            color: var(--sap-text);
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 700;
            letter-spacing: -.015em;
        }

        .page-subtitle {
            margin-top: .375rem;
            color: var(--sap-muted);
            font-size: .875rem;
        }

        .sap-page-body {
            padding: 1rem 1.5rem 1.5rem;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .75rem;
            margin-bottom: 1rem;
        }

        .kpi-tile {
            background: var(--sap-card);
            border: 1px solid var(--sap-border-soft);
            border-radius: .5rem;
            padding: .875rem 1rem;
            box-shadow: 0 .125rem .375rem rgba(0, 0, 0, .04);
        }

        .kpi-label {
            color: var(--sap-muted);
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .kpi-value {
            margin-top: .25rem;
            font-size: 1.65rem;
            line-height: 1;
            font-weight: 700;
            color: var(--sap-text);
        }

        .sap-card {
            background: var(--sap-card);
            border: 1px solid var(--sap-border);
            border-radius: .5rem;
            box-shadow: 0 .125rem .5rem rgba(0, 0, 0, .06);
            overflow: hidden;
            height: 100%;
        }

        .sap-card-header {
            min-height: 3.25rem;
            padding: .875rem 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fff;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
        }

        .sap-card-title {
            margin: 0;
            color: var(--sap-text);
            font-size: 1rem;
            font-weight: 700;
        }

        .sap-card-subtitle {
            margin-top: .1875rem;
            color: var(--sap-muted);
            font-size: .8125rem;
        }

        .sap-card-body {
            padding: 1rem;
        }

        .form-label {
            color: var(--sap-text);
            font-size: .8125rem;
            font-weight: 700;
            margin-bottom: .3125rem;
        }

        .form-control,
        .form-select {
            min-height: 2.375rem;
            border-radius: .25rem;
            border-color: #89919a;
            color: var(--sap-text);
            font-size: .875rem;
            background-color: #fff;
        }

        .form-control:hover,
        .form-select:hover {
            border-color: var(--sap-accent);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--sap-accent);
            box-shadow: 0 0 0 .125rem rgba(10, 110, 209, .22);
        }

        .form-check-input:checked {
            background-color: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .btn {
            min-height: 2.25rem;
            border-radius: .25rem;
            font-size: .875rem;
            font-weight: 700;
        }

        .btn-primary {
            background: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--sap-accent-hover);
            border-color: var(--sap-accent-hover);
        }

        .btn-outline-primary {
            color: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .btn-outline-primary:hover {
            background: var(--sap-accent);
            border-color: var(--sap-accent);
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .75rem 1rem;
            border-bottom: 1px solid var(--sap-border-soft);
            background: #fbfbfb;
        }

        .table-search {
            max-width: 20rem;
        }

        .user-table-wrap {
            overflow: auto;
            max-height: 66vh;
        }

        .user-table {
            margin: 0;
            min-width: 64rem;
            font-size: .8125rem;
        }

        .user-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f2f2f2;
            color: var(--sap-text);
            border-bottom: 1px solid var(--sap-border);
            font-weight: 700;
            padding: .625rem .75rem;
            white-space: nowrap;
            vertical-align: middle;
        }

        .user-table td {
            padding: .625rem .75rem;
            color: var(--sap-text);
            vertical-align: middle;
            white-space: nowrap;
            max-width: 13rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-table tbody tr:hover {
            background: #f5f9ff;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .25rem;
            min-height: 1.5rem;
            padding: .1875rem .625rem;
            border-radius: 1rem;
            font-size: .75rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .status-active {
            background: var(--sap-success-bg);
            color: var(--sap-success);
            border-color: #b8e6c9;
        }

        .status-inactive {
            background: var(--sap-error-bg);
            color: var(--sap-error);
            border-color: #ffc6c6;
        }

        .action-buttons {
            display: flex;
            gap: .375rem;
            flex-wrap: nowrap;
        }

        .action-buttons .btn {
            min-height: 1.875rem;
            padding: .25rem .5rem;
            font-size: .75rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: var(--topbar-height) 0 0 0;
            z-index: 1030;
            background: rgba(0, 0, 0, .38);
        }

        .empty-state {
            padding: 2.5rem 1rem;
            color: var(--sap-muted);
        }

        @media (max-width: 1199.98px) {
            .kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            :root {
                --side-width: 16rem;
            }

            .shell-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .sap-side-nav {
                transform: translateX(-105%);
                transition: transform .2s ease;
            }

            .sap-side-nav.show {
                transform: translateX(0);
            }

            .sidebar-backdrop.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
            }

            .sap-page-header,
            .sap-page-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .page-title-row {
                flex-direction: column;
            }
        }

        @media (max-width: 767.98px) {
            .sap-shellbar {
                padding-inline: .75rem;
            }

            .shell-logo {
                width: 2rem;
                height: 2rem;
            }

            .shell-subtitle {
                display: none;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .sap-page-body {
                padding: .75rem;
            }

            .sap-card-header,
            .sap-card-body,
            .table-toolbar {
                padding-left: .75rem;
                padding-right: .75rem;
            }

            .table-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .table-search {
                max-width: none;
            }
        }

        @media (max-width: 575.98px) {
            .page-title {
                font-size: 1.25rem;
            }

            .sap-page-header {
                padding-top: 1rem;
            }
        }
    </style>
</head>

<body>
<header class="sap-shellbar">
    <button class="shell-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">☰</button>

    <div class="shell-logo" aria-hidden="true">
        <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
    </div>

    <div class="shell-title-wrap">
        <div class="shell-title">NBC Rawmats Traceability</div>
        <div class="shell-subtitle">Administration workspace</div>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-layout">
    <?php app_sidebar('admin'); ?>

    <main class="main-content">
        <section class="sap-page-header">
            <div class="page-kicker">Identity and Access Management</div>
            <div class="page-title-row">
                <div>
                    <h1 class="page-title">Account / User Management</h1>
                    <div class="page-subtitle">Maintain employee accounts, roles, assigned devices, and operating access.</div>
                </div>

                <?php if ($editUser): ?>
                    <a class="btn btn-outline-secondary" href="pages/admin/admin_users.php">Cancel Editing</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="sap-page-body">
            <div class="kpi-grid" aria-label="User account summary">
                <div class="kpi-tile">
                    <div class="kpi-label">Total Accounts</div>
                    <div class="kpi-value"><?= (int)$totalUsers ?></div>
                </div>
                <div class="kpi-tile">
                    <div class="kpi-label">Active</div>
                    <div class="kpi-value"><?= (int)$activeUsers ?></div>
                </div>
                <div class="kpi-tile">
                    <div class="kpi-label">Inactive</div>
                    <div class="kpi-value"><?= (int)$inactiveUsers ?></div>
                </div>
            </div>

            <div class="row g-3 align-items-stretch">
                <div class="col-12 col-xl-4">
                    <div class="sap-card">
                        <div class="sap-card-header">
                            <div>
                                <h2 class="sap-card-title"><?= $editUser ? 'Edit User' : 'Create User' ?></h2>
                                <div class="sap-card-subtitle">
                                    <?= $editUser ? 'Update selected account details.' : 'Create a new authorized account.' ?>
                                </div>
                            </div>
                        </div>

                        <div class="sap-card-body">
                            <form method="post" action="actions/user_save.php" autocomplete="off">
                                <input type="hidden" name="user_id" value="<?= h($editUser['UserID'] ?? '') ?>">

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label" for="username">Employee Code</label>
                                        <input class="form-control" id="username" name="username" required value="<?= h($editUser['Username'] ?? '') ?>">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="full_name">Full Name</label>
                                        <input class="form-control" id="full_name" name="full_name" value="<?= h($editUser['FullName'] ?? '') ?>">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="password">Password <?= $editUser ? '(leave blank to keep current)' : '' ?></label>
                                        <input class="form-control" id="password" type="password" name="password" <?= $editUser ? '' : 'required' ?>>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="role">Role</label>
                                        <select class="form-select" name="role" id="role" required onchange="toggleRoleFields()">
                                            <?php foreach ([ROLE_PICKER, ROLE_ISSUER, ROLE_REQUESTOR, ROLE_ADMIN] as $r): ?>
                                                <option value="<?= h($r) ?>" <?= strtolower($editUser['RoleName'] ?? '') === $r ? 'selected' : '' ?>>
                                                    <?= h(role_label($r)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12" id="requestorSectionBox">
                                        <label class="form-label" for="requestor_section">Requestor Section</label>
                                        <select class="form-select" id="requestor_section" name="requestor_section">
                                            <option value="">-- Select requestor section --</option>
                                            <?php foreach (['Backend', 'Cut and Crimp', 'Sub-Assy', 'Kitting'] as $section): ?>
                                                <option value="<?= h($section) ?>" <?= ($editUser['RequestorSection'] ?? '') === $section ? 'selected' : '' ?>>
                                                    <?= h($section) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12" id="receiverAreaBox">
                                        <label class="form-label" for="receiver_area">Receiver Area</label>
                                        <select class="form-select" id="receiver_area" name="receiver_area">
                                            <option value="">-- Select receiver area --</option>
                                            <?php foreach (receiver_areas() as $area): ?>
                                                <option value="<?= h($area) ?>" <?= ($editUser['ReceiverArea'] ?? '') === $area ? 'selected' : '' ?>>
                                                    <?= h($area) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-6 col-xl-12">
                                        <label class="form-label" for="device_hostname">Assigned Hostname</label>
                                        <input class="form-control" id="device_hostname" name="device_hostname" value="<?= h($editUser['DeviceHostname'] ?? '') ?>">
                                    </div>

                                    <div class="col-12 col-md-6 col-xl-12">
                                        <label class="form-label" for="device_ip">Assigned IP Address</label>
                                        <input class="form-control" id="device_ip" name="device_ip" value="<?= h($editUser['DeviceIPAddress'] ?? '') ?>">
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !isset($editUser['IsActive']) || (int)$editUser['IsActive'] === 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="is_active">Active account</label>
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                                        <button class="btn btn-primary" type="submit"><?= $editUser ? 'Update User' : 'Add User' ?></button>
                                        <?php if ($editUser): ?>
                                            <a class="btn btn-outline-secondary" href="pages/admin/admin_users.php">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-8">
                    <div class="sap-card">
                        <div class="sap-card-header">
                            <div>
                                <h2 class="sap-card-title">Users</h2>
                                <div class="sap-card-subtitle"><?= (int)$totalUsers ?> account(s) registered.</div>
                            </div>
                        </div>

                        <div class="table-toolbar">
                            <div class="fw-semibold">User Directory</div>
                            <input class="form-control table-search" id="userSearch" type="search" placeholder="Search users, role, device, IP..." aria-label="Search users">
                        </div>

                        <div class="user-table-wrap">
                            <table class="table table-hover align-middle user-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>Employee Code</th>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th>Section</th>
                                        <th>Area</th>
                                        <th>Hostname</th>
                                        <th>IP Address</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center empty-state">No users found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $u): ?>
                                            <tr>
                                                <td title="<?= h($u['Username']) ?>"><?= h($u['Username']) ?></td>
                                                <td title="<?= h($u['FullName']) ?>"><?= h($u['FullName']) ?></td>
                                                <td title="<?= h(role_label(strtolower($u['RoleName']))) ?>"><?= h(role_label(strtolower($u['RoleName']))) ?></td>
                                                <td title="<?= h($u['RequestorSection'] ?? '') ?>"><?= h($u['RequestorSection'] ?? '') ?></td>
                                                <td title="<?= h($u['ReceiverArea']) ?>"><?= h($u['ReceiverArea']) ?></td>
                                                <td title="<?= h($u['DeviceHostname']) ?>"><?= h($u['DeviceHostname']) ?></td>
                                                <td title="<?= h($u['DeviceIPAddress']) ?>"><?= h($u['DeviceIPAddress']) ?></td>
                                                <td>
                                                    <?php if ((int)$u['IsActive']): ?>
                                                        <span class="status-pill status-active">● Active</span>
                                                    <?php else: ?>
                                                        <span class="status-pill status-inactive">● Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a class="btn btn-sm btn-outline-primary" href="pages/admin/admin_users.php?edit=<?= (int)$u['UserID'] ?>">Edit</a>
                                                        <a class="btn btn-sm btn-outline-danger" href="actions/user_delete.php?id=<?= (int)$u['UserID'] ?>" onclick="return confirm('Deactivate this user?')">Delete</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleRoleFields() {
    const role = document.getElementById('role');
    const receiverAreaBox = document.getElementById('receiverAreaBox');
    const requestorSectionBox = document.getElementById('requestorSectionBox');

    if (!role) {
        return;
    }

    if (receiverAreaBox) {
        receiverAreaBox.style.display = role.value === 'receiver' ? '' : 'none';
    }

    if (requestorSectionBox) {
        requestorSectionBox.style.display = role.value === 'requestor' ? '' : 'none';
    }
}

toggleRoleFields();

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function closeSidebar() {
    if (sidebar) {
        sidebar.classList.remove('show');
    }
    if (sidebarBackdrop) {
        sidebarBackdrop.classList.remove('show');
    }
}

if (sidebarToggle && sidebar && sidebarBackdrop) {
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
    });
}

if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
}

document.querySelectorAll('.sap-nav-link').forEach(function (link) {
    link.addEventListener('click', closeSidebar);
});

const userSearch = document.getElementById('userSearch');
const usersTable = document.getElementById('usersTable');

if (userSearch && usersTable) {
    userSearch.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        usersTable.querySelectorAll('tbody tr').forEach(function (row) {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
}
</script>

</body>
</html>