<?php
// Account chip + dropdown, shared by the admin and scholar top bars.
$headerAccount = isset($headerAccount) && is_array($headerAccount)
    ? $headerAccount
    : currentAccount(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
$headerPrivacy = $headerPrivacy ?? privacyLink();
?>
<details class="account-menu">
    <summary class="account-chip" aria-label="Account menu for <?php echo h($headerAccount['name']); ?>">
        <span class="account-chip__avatar" aria-hidden="true"><?php echo h($headerAccount['initials']); ?></span>
        <span class="account-chip__name"><?php echo h($headerAccount['name']); ?></span>
        <span class="account-chip__chevron" aria-hidden="true"></span>
    </summary>
    <div class="account-menu__panel">
        <div class="account-menu__who">
            <span class="account-menu__avatar" aria-hidden="true"><?php echo h($headerAccount['initials']); ?></span>
            <span class="account-menu__id">
                <strong><?php echo h($headerAccount['name']); ?></strong>
                <span><?php echo h($headerAccount['email']); ?></span>
                <span class="account-menu__role"><?php echo h(ucfirst($headerAccount['role'])); ?></span>
            </span>
        </div>
        <div class="account-menu__sep" role="separator"></div>
        <a href="/GRANTED/settings.php"><span class="icon-label"><?php echo uiIcon('settings'); ?>Settings</span></a>
        <?php if ($headerPrivacy): ?>
            <a href="<?php echo sanitize($headerPrivacy['href']); ?>"<?php echo $headerPrivacy['external'] ? ' target="_blank" rel="noopener"' : ''; ?>>Privacy</a>
        <?php endif; ?>
        <div class="account-menu__sep" role="separator"></div>
        <a class="account-menu__logout" href="/GRANTED/auth/logout.php"><span class="icon-label"><?php echo uiIcon('log-out'); ?>Log out</span></a>
    </div>
</details>
