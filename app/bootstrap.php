<?php

spl_autoload_register(function($class) {
    switch ($class) {
        case 'src\One':
            include __DIR__ . '/../src/One.php';
            break;
        case 'src\Two':
            include __DIR__ . '/../src/Two.php';
            break;
    }
});