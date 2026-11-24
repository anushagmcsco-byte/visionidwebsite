<?php
// Copy to config.php, create a MySQL database/user in cPanel, and fill these values.
return [
    'db' => ['host' => 'localhost', 'name' => 'cpanel_visionid', 'user' => 'cpanel_user', 'password' => 'CHANGE_ME'],
    'admin_username' => 'admin',
    // Generate with: php -r "echo password_hash('your-long-password', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'session_name' => 'visionid_admin',
];
