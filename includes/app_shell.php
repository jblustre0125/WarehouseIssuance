<?php

require_once __DIR__ . '/auth.php';

function app_sidebar_icon($name)
{
    $icons = [
        'dashboard' => '<rect x="4" y="5" width="6" height="5" rx="1.25"></rect><rect x="14" y="5" width="6" height="5" rx="1.25"></rect><rect x="4" y="14" width="6" height="5" rx="1.25"></rect><rect x="14" y="14" width="6" height="5" rx="1.25"></rect>',
        'requestor' => '<rect x="6" y="4" width="12" height="16" rx="2"></rect><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h4"></path>',
        'picker' => '<path d="M5 8.5 12 4l7 4.5v7L12 20l-7-4.5v-7Z"></path><path d="M12 12.5 19 8"></path><path d="M12 12.5 5 8"></path><path d="M12 12.5V20"></path><path d="m9 6 7 4.5"></path>',
        'issuer' => '<path d="M4 7h8"></path><path d="M4 12h10"></path><path d="M4 17h8"></path><path d="M15 8l5 4-5 4"></path>',
        'receiver' => '<path d="M5 4h14v11H5V4Z"></path><path d="M8 20h8"></path><path d="M12 15v5"></path><path d="m9 12 3 3 3-3"></path>',
        'issuer_report' => '<path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"></path><path d="M14 3v5h5"></path><path d="M9 16l2-2 2 1 2-4"></path>',
        'requestor_report' => '<path d="M7 3h10a2 2 0 0 1 2 2v16H5V5a2 2 0 0 1 2-2Z"></path><path d="M9 8h6"></path><path d="M9 12h3"></path><path d="m9 17 2 2 4-5"></path>',
        'transactions' => '<path d="M7 7h13"></path><path d="m16 3 4 4-4 4"></path><path d="M17 17H4"></path><path d="m8 13-4 4 4 4"></path>',
        'admin' => '<circle cx="9" cy="8" r="3.5"></circle><path d="M3 20a6 6 0 0 1 12 0"></path><circle cx="18" cy="17" r="2"></circle><path d="M18 13.5V12"></path><path d="M18 22v-1.5"></path><path d="m21 15.25-1.25.75"></path><path d="m16.25 18-1.25.75"></path>',
        'logout' => '<path d="M10 17 15 12l-5-5"></path><path d="M15 12H3"></path><path d="M21 19V5a2 2 0 0 0-2-2h-5"></path>',
    ];

    $paths = $icons[$name] ?? '<circle cx="12" cy="12" r="8"></circle>';

    return '<svg class="sap-nav-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
}

function app_sidebar_link($active, $key, $href, $icon, $label)
{
    $isActive = $active === $key;
    ?>
    <a class="sap-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= h(app_path($href)) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
        <span class="sap-nav-icon"><?= app_sidebar_icon($icon) ?></span>
        <span class="sap-nav-label"><?= h($label) ?></span>
    </a>
    <?php
}

function app_sidebar_section($label)
{
    ?>
    <div class="sap-nav-section"><?= h($label) ?></div>
    <?php
}

function app_sidebar($active = '')
{
    $u = current_user() ?: [];
    $role = strtolower((string)($u['role'] ?? ''));
    $name = $u['full_name'] ?? $u['username'] ?? 'User';
    $avatar = strtoupper(substr((string)$name, 0, 1));
    ?>
    <aside class="sap-side-nav" id="sidebar" aria-label="Main navigation">
        <div class="side-nav-header">
            <div class="side-nav-heading">
                <div class="side-nav-title">Warehouse Issuance</div>
                <div class="side-nav-eyebrow">Main menu</div>
            </div>
        </div>

        <nav class="sap-nav-menu">
            <?php if ($role === ROLE_ADMIN): ?>
                <?php app_sidebar_section('Main'); ?>
                <?php app_sidebar_link($active, 'dashboard', 'pages/dashboard/verification_dashboard.php', 'dashboard', 'Verification Dashboard'); ?>

                <?php app_sidebar_section('Transactions'); ?>
                <?php app_sidebar_link($active, 'requestor', 'pages/requestor/requestor.php', 'requestor', 'Requestor'); ?>
                <?php app_sidebar_link($active, 'picker', 'pages/picker/picker.php', 'picker', 'Picker'); ?>
                <?php app_sidebar_link($active, 'issuer', 'pages/issuer/issuer.php', 'issuer', 'Issuer'); ?>
                <?php app_sidebar_link($active, 'receiver', 'pages/receiver/receiver.php', 'receiver', 'Receiver'); ?>

                <?php app_sidebar_section('Reports'); ?>
                <?php app_sidebar_link($active, 'issuer_report', 'pages/issuer/issuer_scan_report.php', 'issuer_report', 'Issuer Scan Report'); ?>
                <?php app_sidebar_link($active, 'requestor_report', 'pages/requestor/requestor_report.php', 'requestor_report', 'Requestor Report'); ?>
                <?php app_sidebar_link($active, 'transactions', 'pages/reports/view_transactions.php', 'transactions', 'View Transactions'); ?>

                <?php app_sidebar_section('Admin'); ?>
                <?php app_sidebar_link($active, 'admin', 'pages/admin/admin_users.php', 'admin', 'User Management'); ?>
            <?php elseif ($role === ROLE_REQUESTOR): ?>
                <?php app_sidebar_section('Transactions'); ?>
                <?php app_sidebar_link($active, 'requestor', 'pages/requestor/requestor.php', 'requestor', 'Issue Request'); ?>

                <?php app_sidebar_section('Reports'); ?>
                <?php app_sidebar_link($active, 'requestor_report', 'pages/requestor/requestor_report.php', 'requestor_report', 'Requestor Report'); ?>
            <?php elseif ($role === ROLE_PICKER): ?>
                <?php app_sidebar_section('Transactions'); ?>
                <?php app_sidebar_link($active, 'picker', 'pages/picker/picker.php', 'picker', 'Pick Barcode Tags'); ?>
            <?php elseif ($role === ROLE_ISSUER): ?>
                <?php app_sidebar_section('Transactions'); ?>
                <?php app_sidebar_link($active, 'issuer', 'pages/issuer/issuer.php', 'issuer', 'Issuer Warehouse'); ?>

                <?php app_sidebar_section('Reports'); ?>
                <?php app_sidebar_link($active, 'issuer_report', 'pages/issuer/issuer_scan_report.php', 'issuer_report', 'Issuer Scan Report'); ?>
            <?php elseif ($role === ROLE_RECEIVER): ?>
                <?php app_sidebar_section('Transactions'); ?>
                <?php app_sidebar_link($active, 'receiver', 'pages/receiver/receiver.php', 'receiver', 'Receiver Scan'); ?>

                <?php app_sidebar_section('Reports'); ?>
                <?php app_sidebar_link($active, 'transactions', 'pages/reports/view_transactions.php', 'transactions', 'View Transactions'); ?>
            <?php else: ?>
                <?php app_sidebar_section('Main'); ?>
                <?php app_sidebar_link($active, 'transactions', 'pages/reports/view_transactions.php', 'transactions', 'View Transactions'); ?>
            <?php endif; ?>
        </nav>

        <div class="sap-side-footer">
            <div class="side-user-card" aria-label="Signed in user">
                <div class="side-user-avatar"><?= h($avatar) ?></div>
                <div class="side-user-details">
                    <div class="side-user-name"><?= h($name) ?></div>
                    <div class="side-user-role"><?= h($u['role'] ?? '') ?></div>
                </div>
            </div>
            <a class="logout-link" href="<?= h(app_path('pages/auth/logout.php')) ?>">
                <span class="logout-icon"><?= app_sidebar_icon('logout') ?></span>
                <span>Logout</span>
            </a>
        </div>
    </aside>
    <?php
}

?>
