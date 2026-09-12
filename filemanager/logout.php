<?php

require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/helpers/AuditLogger.php';

if (isLoggedIn()) {

    $user = currentUser();

    AuditLogger::log(
        "LOGOUT",
        "SUCCESS",
        [
            "username" => $user['username'] ?? "Unknown"
        ]
    );
}

session_unset();
session_destroy();

redirect('/index.php');