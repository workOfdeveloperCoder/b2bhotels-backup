<?php
// ============================================================================
// AGENCY FORM / FIELDS
// The agency profile inputs, in the order the agent sees them: agency name,
// license number, city, country, phone, address, email, and the logo as a
// square picker (click it to choose an image; the new one shows at once).
//
// Set before including:
//   $afAgency          the agencies row (or [])
//   $afName            fn(string $field): string  — the input name of a field
//                      (agent page: the field itself; admin: agency[field])
//   $afLogoName        the name of the file input
//   $afEmailReadonly   true when the email cannot be changed here
//   $afRequired        true when every text field is required
// Saved by saveAgencyProfile() in app/lib/functions.php.
// ============================================================================
@$SECURE or die('Access Denied!');

require __DIR__ . '/styles.php';

$afAgency = is_array($afAgency ?? null) ? $afAgency : [];
$afName = $afName ?? fn($f) => $f;
$afLogoName = $afLogoName ?? 'logo';
$afRequired = !empty($afRequired);
$afReq = $afRequired ? ' required' : '';
$afStar = $afRequired ? ' *' : '';
$afVal = fn($f) => htmlspecialchars((string) ($afAgency[$f] ?? ''));
$afLogo = trim((string) ($afAgency['logo'] ?? ''));
$afLogoUrl = $afLogo === '' ? '' : (preg_match('#^https?://#i', $afLogo) ? $afLogo : root . ltrim($afLogo, '/'));
$afId = 'af' . substr(md5((string) mt_rand()), 0, 6);
?>
<div class="af">
  <div>
    <label class="af-logo<?= $afLogoUrl === '' ? ' is-empty' : '' ?>" for="<?= $afId ?>-logo" title="<?= htmlspecialchars((string) T::agency_logo) ?>">
      <input type="file" id="<?= $afId ?>-logo" name="<?= htmlspecialchars($afLogoName) ?>" accept="image/png,image/jpeg"
             onchange="(function (i) { var f = i.files && i.files[0], box = i.closest('.af-logo'); if (!f) return;
               var img = box.querySelector('.af-logo-img'); if (!img) { img = document.createElement('img'); img.className = 'af-logo-img'; img.alt = ''; box.insertBefore(img, box.querySelector('.af-logo-cta')); }
               var rd = new FileReader(); rd.onload = function () { img.src = rd.result; }; rd.readAsDataURL(f); var e = box.querySelector('.af-logo-empty'); if (e) e.remove(); box.classList.remove('is-empty'); })(this)">
      <?php if ($afLogoUrl !== ''): ?>
        <img class="af-logo-img" src="<?= htmlspecialchars($afLogoUrl) ?>" alt="">
      <?php else: ?>
        <span class="af-logo-empty"><span class="material-symbols-outlined">add_photo_alternate</span><?= htmlspecialchars((string) T::upload_logo) ?></span>
      <?php endif; ?>
      <span class="af-logo-cta"><span class="material-symbols-outlined">photo_camera</span><?= htmlspecialchars((string) T::change_logo) ?></span>
    </label>
    <p class="af-hint"><?= htmlspecialchars((string) T::supported_formats) ?>: JPG, PNG · 2 MB</p>
  </div>

  <div class="af-fields">
    <div class="form-control af-wide">
      <label for="<?= $afId ?>-name"><?= T::agency_name . $afStar ?></label>
      <input type="text" id="<?= $afId ?>-name" class="input" name="<?= $afName('agency_name') ?>" value="<?= $afVal('agency_name') ?>"<?= $afReq ?>>
    </div>
    <div class="form-control">
      <label for="<?= $afId ?>-license"><?= T::license_number . $afStar ?></label>
      <input type="text" id="<?= $afId ?>-license" class="input" name="<?= $afName('license_number') ?>" value="<?= $afVal('license_number') ?>"<?= $afReq ?>>
    </div>
    <div class="form-control">
      <label for="<?= $afId ?>-city"><?= T::city . $afStar ?></label>
      <input type="text" id="<?= $afId ?>-city" class="input" name="<?= $afName('city') ?>" value="<?= $afVal('city') ?>"<?= $afReq ?>>
    </div>
    <div class="form-control">
      <label for="<?= $afId ?>-country"><?= T::country . $afStar ?></label>
      <input type="text" id="<?= $afId ?>-country" class="input" name="<?= $afName('country') ?>" value="<?= $afVal('country') ?>"<?= $afReq ?>>
    </div>
    <div class="form-control">
      <label for="<?= $afId ?>-phone"><?= T::phone . $afStar ?></label>
      <input type="tel" id="<?= $afId ?>-phone" class="input" name="<?= $afName('phone') ?>" value="<?= $afVal('phone') ?>"<?= $afReq ?>>
    </div>
    <div class="form-control af-wide">
      <label for="<?= $afId ?>-address"><?= T::address . $afStar ?></label>
      <textarea id="<?= $afId ?>-address" class="textarea" name="<?= $afName('address') ?>" rows="2"<?= $afReq ?>><?= $afVal('address') ?></textarea>
    </div>
    <div class="form-control af-wide">
      <label for="<?= $afId ?>-email"><?= T::email . $afStar ?></label>
      <input type="email" id="<?= $afId ?>-email" class="input<?= !empty($afEmailReadonly) ? ' bg-gray-100' : '' ?>" name="<?= $afName('email') ?>" value="<?= $afVal('email') ?>"<?= $afReq ?><?= !empty($afEmailReadonly) ? ' readonly' : '' ?>>
    </div>
  </div>
</div>
