<?php
require_once __DIR__ . '/../includes/auth.php';

require_role(ROLE_ADMIN);

$conn = get_whpokayoke_connection();

$id = (int)($_POST['user_id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$password = (string)($_POST['password'] ?? '');
$role = strtolower(trim($_POST['role'] ?? ''));

$receiverArea = $role === ROLE_RECEIVER
    ? trim($_POST['receiver_area'] ?? '')
    : '';

$requestorSection = $role === ROLE_REQUESTOR
    ? trim($_POST['requestor_section'] ?? '')
    : '';

$hostname = trim($_POST['device_hostname'] ?? '');
$ip = trim($_POST['device_ip'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

$validRoles = [
    ROLE_ISSUER,
    ROLE_PICKER,
    ROLE_REQUESTOR,
    ROLE_RECEIVER,
    ROLE_SAP_ENCODER,
    ROLE_ADMIN,
];

if (!in_array($role, $validRoles, true)) {
    app_error('Invalid role.');
}

if ($username === '') {
    app_error('Username is required.');
}

if ($id <= 0 && $password === '') {
    app_error('Password is required for new users.');
}

$validRequestorSections = [
    '',
    'Backend',
    'Cut and Crimp',
    'Sub-Assy',
    'Kitting'
];

if (!in_array($requestorSection, $validRequestorSections, true)) {
    app_error('Invalid requestor section.');
}

if ($id > 0) {
    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $sql = '
            UPDATE AppUsers
            SET
                Username = ?,
                FullName = ?,
                PasswordHash = ?,
                RoleName = ?,
                ReceiverArea = ?,
                RequestorSection = ?,
                DeviceHostname = ?,
                DeviceIPAddress = ?,
                IsActive = ?,
                UpdatedAt = GETDATE()
            WHERE UserID = ?
        ';

        $params = [
            $username,
            $fullName,
            $hash,
            $role,
            $receiverArea,
            $requestorSection,
            $hostname,
            $ip,
            $isActive,
            $id
        ];
    } else {
        $sql = '
            UPDATE AppUsers
            SET
                Username = ?,
                FullName = ?,
                RoleName = ?,
                ReceiverArea = ?,
                RequestorSection = ?,
                DeviceHostname = ?,
                DeviceIPAddress = ?,
                IsActive = ?,
                UpdatedAt = GETDATE()
            WHERE UserID = ?
        ';

        $params = [
            $username,
            $fullName,
            $role,
            $receiverArea,
            $requestorSection,
            $hostname,
            $ip,
            $isActive,
            $id
        ];
    }
} else {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = '
        INSERT INTO AppUsers
            (
                Username,
                FullName,
                PasswordHash,
                RoleName,
                ReceiverArea,
                RequestorSection,
                DeviceHostname,
                DeviceIPAddress,
                IsActive
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ';

    $params = [
        $username,
        $fullName,
        $hash,
        $role,
        $receiverArea,
        $requestorSection,
        $hostname,
        $ip,
        $isActive
    ];
}

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    app_error(sqlsrv_fail_message());
}

header('Location: ' . app_path('pages/admin/admin_users.php'));
exit;
?>