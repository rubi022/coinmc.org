<?php
$site = e(cfg('site_name'));
$flashes = $flash ?? [];
?>
<div class="auth-wrap">
<?php foreach ($flashes as [$ft, $fm]): ?><div class="notice <?= e($ft) ?>"><?= e($fm) ?></div><?php endforeach; ?>

<?php if ($mode === 'login'): ?>
  <form class="card auth" method="post" action="/login/" autocomplete="on">
    <h1>Log in</h1>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Email<input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn wide" type="submit">Log in</button>
    <p class="small muted"><a href="/forgot/">Forgot password?</a> · New here? <a href="/register/">Create an account</a></p>
  </form>

<?php elseif ($mode === 'login_2fa'): ?>
  <form class="card auth" method="post" action="/login/2fa/">
    <h1>Two-factor verification</h1>
    <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Code<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus placeholder="123456"></label>
    <button class="btn wide" type="submit">Verify</button>
    <p class="small muted"><a href="/login/">Start over</a></p>
  </form>

<?php elseif ($mode === 'register'): ?>
  <form class="card auth" method="post" action="/register/" autocomplete="on">
    <h1>Create your free account</h1>
    <p class="muted">Save your watchlist across devices and get price alerts and market updates.</p>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <label>Name <span class="muted">(optional)</span><input type="text" name="name" value="<?= e($v['name']) ?>" maxlength="120" autocomplete="name"></label>
    <label>Email<input type="email" name="email" value="<?= e($v['email']) ?>" required autocomplete="email"></label>
    <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"><small class="muted">At least 8 characters.</small></label>
    <label>Confirm password<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="newsletter" value="1" <?= $v['newsletter'] ? 'checked' : '' ?>> Send me the market newsletter</label>
    <label class="check"><input type="checkbox" name="terms" value="1" required> I agree to the <a href="/terms/" target="_blank">Terms</a> and <a href="/privacy/" target="_blank">Privacy Policy</a></label>
    <button class="btn wide" type="submit">Create account</button>
    <p class="small muted">Already registered? <a href="/login/">Log in</a></p>
  </form>

<?php elseif ($mode === 'forgot'): ?>
  <form class="card auth" method="post" action="/forgot/">
    <h1>Reset your password</h1>
    <?php if (!empty($done)): ?>
      <div class="notice ok">If an account exists for that email, a reset link is on its way. It is valid for one hour.</div>
    <?php else: ?>
      <p class="muted">Enter your email and we’ll send you a link to choose a new password.</p>
      <?= csrf_field() ?>
      <label>Email<input type="email" name="email" required autofocus></label>
      <button class="btn wide" type="submit">Send reset link</button>
    <?php endif; ?>
    <p class="small muted"><a href="/login/">Back to log in</a></p>
  </form>

<?php elseif ($mode === 'reset'): ?>
  <form class="card auth" method="post" action="/reset/">
    <h1>Choose a new password</h1>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <label>New password<input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
    <label>Confirm password<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn wide" type="submit">Update password</button>
  </form>

<?php elseif ($mode === 'message'): ?>
  <div class="card auth">
    <h1><?= e($seo['title'] ? explode(' | ', $seo['title'])[0] : 'Notice') ?></h1>
    <p><?= e($text) ?></p>
    <?php if (!empty($post)): ?><form method="post" action="<?= e($post[0]) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e($post[1]) ?></button></form><?php endif; ?>
    <?php if (!empty($cta)): ?><p><a class="btn" href="<?= e($cta[0]) ?>"><?= e($cta[1]) ?></a></p><?php endif; ?>
  </div>

<?php elseif ($mode === 'account'): ?>
  <div class="acct">
    <h1>My account</h1>
    <?php if ($msg): ?><div class="notice ok"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?php if (!$u['email_verified_at']): ?>
      <div class="notice warn">Your email isn’t confirmed yet.
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="link-btn" type="submit">Resend confirmation email</button></form></div>
    <?php endif; ?>
    <?php if ($u['deletion_requested_at']): ?>
      <div class="notice err">Your account is scheduled for deletion on <?= e(fmt_date(strtotime($u['deletion_requested_at'] . ' UTC') + 7 * 86400)) ?>.
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_delete"><button class="link-btn" type="submit">Cancel deletion</button></form></div>
    <?php endif; ?>
    <?php if ($u['role'] !== 'user'): ?><p><a class="btn" href="/admin/">Open admin panel</a></p><?php endif; ?>

    <div class="grid2">
      <section class="card">
        <h2>Profile</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <label>Email<input type="email" value="<?= e($u['email']) ?>" disabled></label>
          <label>Name<input type="text" name="name" value="<?= e($u['name']) ?>" maxlength="120"></label>
          <label class="check"><input type="checkbox" name="newsletter" value="1" <?= $u['newsletter'] ? 'checked' : '' ?>> Market newsletter</label>
          <button class="btn" type="submit">Save</button>
        </form>
      </section>
      <section class="card">
        <h2>Password &amp; security</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
          <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
          <label>New password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
          <label>Confirm new password<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
          <button class="btn" type="submit">Change password</button>
        </form>
        <p class="small"><a href="/account/security/">Two-factor authentication: <strong><?= $u['totp_enabled'] ? 'On' : 'Off' ?></strong> ›</a></p>
      </section>
    </div>

    <section class="card">
      <h2>Your watchlist <span class="muted small">(<?= count($watch) ?> coins)</span></h2>
      <?php if ($watch): ?>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th class="left">Coin</th><th>Price</th><th>24h</th></tr></thead><tbody>
        <?php foreach ($watch as $c): ?>
          <tr><td class="left"><a class="coin-cell" href="/currencies/<?= e($c['slug']) ?>/"><?= coin_img($c, 22) ?><span class="cn"><?= e($c['name']) ?></span><span class="cs"><?= e(strtoupper($c['symbol'])) ?></span></a></td><td><?= fmt_price($c['price']) ?></td><td><?= pct($c['change_24h']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><p class="muted">Tap ☆ next to any coin to add it. Your watchlist syncs to this account on every device.</p><?php endif; ?>
    </section>

    <section class="card">
      <h2>Your data</h2>
      <p class="muted">Download everything we store about you, or delete your account (it is removed permanently after 7 days).</p>
      <p><a class="btn ghost" href="/account/export/">Download my data (JSON)</a></p>
      <?php if (!$u['deletion_requested_at'] && $u['role'] === 'user'): ?>
      <details><summary class="link-btn">Delete my account…</summary>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <label>Confirm your password<input type="password" name="confirm_password" required autocomplete="current-password"></label>
          <button class="btn danger" type="submit">Schedule deletion</button></form>
      </details>
      <?php endif; ?>
      <form method="post" action="/logout/"><?= csrf_field() ?><button class="btn ghost" type="submit">Log out</button></form>
    </section>
  </div>

<?php elseif ($mode === 'security'): ?>
  <div class="acct">
    <p class="crumbs"><a href="/account/">Account</a> › Security</p>
    <h1>Two-factor authentication</h1>
    <?php if ($msg): ?><div class="notice ok"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($codes): ?>
      <section class="card"><h2>Recovery codes</h2><p class="muted">Store these somewhere safe. Each can be used once if you lose your phone.</p>
        <pre class="codes"><?= e(implode("\n", $codes)) ?></pre></section>
    <?php endif; ?>
    <?php if ($u['totp_enabled']): ?>
      <section class="card"><h2>Status: On ✅</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
          <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
          <label>Current 6-digit code<input type="text" name="code" inputmode="numeric" required></label>
          <button class="btn danger" type="submit">Turn off 2FA</button></form></section>
    <?php elseif ($setup): ?>
      <section class="card"><h2>Set up an authenticator app</h2>
        <ol>
          <li>Open Google Authenticator, 1Password, Authy or similar and choose <em>Add account → Enter a setup key</em>.</li>
          <li>Account: <code><?= e($u['email']) ?></code> · Key: <code class="big"><?= e(trim(chunk_split($setup['secret'], 4, ' '))) ?></code> · Type: time-based.</li>
          <li>Enter the 6-digit code the app shows:</li>
        </ol>
        <p class="small muted">Or open on your phone: <a href="<?= e($setup['uri']) ?>">authenticator link</a></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enable">
          <label>6-digit code<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
          <button class="btn" type="submit">Turn on 2FA</button></form></section>
    <?php endif; ?>
    <section class="card"><h2>Recent sign-ins</h2>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th class="left">When (UTC)</th><th class="left">IP</th><th class="left">Result</th></tr></thead><tbody>
      <?php foreach ($hist as $h): ?><tr><td class="left"><?= e($h['created_at']) ?></td><td class="left"><?= e($h['ip']) ?></td><td class="left"><?= $h['success'] ? '✓ ' : '✗ ' ?><?= e($h['reason']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></section>
  </div>
<?php endif; ?>
</div>
