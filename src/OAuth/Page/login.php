<?php

/*
    The login form (spec §5.3 step 6). Rendered by Pages::render().
*/

/** @var callable(mixed): string $e */
/** @var string $clientName */
/** @var string $redirectHost */
/** @var array<int, string> $companies company id => name */
/** @var int|null $company the one to preselect */
/** @var string $user the user name as typed, after a failed attempt */
/** @var array<string, string> $hidden the authorize request's parameters */
/** @var string $csrf */
/** @var string|null $error */
?>
<main>
  <h1>Sign in to FrontAccounting</h1>
  <p><strong><?= $e($clientName) ?></strong> <span class="muted">(<?= $e($redirectHost) ?>)</span>
     wants to connect to FrontAccounting. Sign in with your FrontAccounting user name and password to continue.</p>
<?php if ($error !== null) : ?>
  <p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
  <form method="post">
<?php foreach ($hidden as $name => $value) : ?>
    <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="login">
<?php if (count($companies) === 1) : ?>
    <input type="hidden" name="company" value="<?= $e(array_keys($companies)[0]) ?>">
<?php else : ?>
    <label>Company
      <select name="company" required>
    <?php foreach ($companies as $id => $name) : ?>
        <option value="<?= $e($id) ?>"<?= $id === $company ? ' selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <label>User name
      <input type="text" name="user" value="<?= $e($user) ?>" autocomplete="username" autocapitalize="none"
        spellcheck="false" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <div class="buttons">
      <button type="submit" class="primary">Sign in</button>
    </div>
  </form>
</main>
