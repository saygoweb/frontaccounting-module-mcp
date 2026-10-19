<?php

// The GraphQL module's autoloader first, as index.php does at runtime, when it is
// beside this one (the CI image and dev environments); unit tests need only ours.
$graphql = dirname(__DIR__, 2) . '/graphql/vendor/autoload.php';
if (is_file($graphql)) {
    require $graphql;
}
require dirname(__DIR__) . '/vendor/autoload.php';
