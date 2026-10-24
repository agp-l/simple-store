<?php
// Test-only router for PHP's built-in server; production uses the root .htaccess.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('~^(.*)/(admin|account)(?:/.*)?$~D', $path, $match) === 1) {
    $script = $match[1] . '/' . $match[2] . '.php';
    $file = $_SERVER['DOCUMENT_ROOT'] . $script;
    if (is_file($file)) {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['PHP_SELF'] = $script;
        require $file;
        return true;
    }
}
return false;
