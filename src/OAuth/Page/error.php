<?php

/*
    An error page: a request that cannot be answered with a redirect. Rendered
    by Pages::render().
*/

/** @var callable(mixed): string $e */
/** @var string $heading */
/** @var string $message */
?>
<main>
  <h1><?= $e($heading) ?></h1>
  <p><?= $e($message) ?></p>
</main>
