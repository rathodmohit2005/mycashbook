<?php
$file = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    return false;
}

$php_file = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'] . '.php';
if (is_file($php_file)) {
    require $php_file;
    return true;
}

return false;
