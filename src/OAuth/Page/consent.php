<?php

/*
    The consent page (spec §5.3 step 7). Rendered by Pages::render().
*/

/** @var callable(mixed): string $e */
/** @var string $clientName */
/** @var string $redirectHost */
/** @var string $login */
/** @var string $companyName */
/** @var string $scope 'read' or 'write', preselected */
/** @var array<string, string> $hidden the authorize request's parameters */
/** @var string $csrf */
?>
<main>
  <h1>Connect <?= $e($clientName) ?>?</h1>
  <p><strong><?= $e($clientName) ?></strong> wants to use FrontAccounting as <strong><?= $e($login) ?></strong>
     in <strong><?= $e($companyName) ?></strong>.</p>
  <p class="muted">It will be sent back to <?= $e($redirectHost) ?>.</p>
  <form method="post">
<?php foreach ($hidden as $name => $value) : ?>
    <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <fieldset>
      <legend>Access</legend>
      <label><input type="radio" name="granted_scope" value="read"<?= $scope === 'write' ? '' : ' checked' ?>>
        Read-only</label>
      <label><input type="radio" name="granted_scope" value="write"<?= $scope === 'write' ? ' checked' : '' ?>>
        Read and change</label>
    </fieldset>
    <p class="muted">An administrator can end this connection at any time with the GraphQL module's
       bin/fa-token revoke.</p>
    <div class="buttons">
      <button type="submit" name="action" value="deny">Deny</button>
      <button type="submit" name="action" value="allow" class="primary">Allow</button>
    </div>
  </form>
</main>
