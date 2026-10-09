<?php
// HEADER / LOGO — brand image, links to the home page.
@$SECURE or die('Access Denied!');
?>
<a href="<?= root ?>" class="hd-logo" aria-label="<?= htmlspecialchars($hd['brand']) ?>">
  <img src="<?= versionedAssetUrl('uploads/global/logo.png') ?>" alt="<?= htmlspecialchars($hd['brand']) ?>" width="160" height="40" decoding="async">
</a>
