<?php
// themes/default/includes/footer.php
@$SECURE or die('Access Denied!');

// Get current language
$currentLang = $_SESSION['app_language'] ?? 'en';
$footer_pages = (cms($db, "footer", "headerfooter"));

// Single loop with deferred parent processing
$footer_menus = [];
$temp = ['parents' => [], 'children' => []];

foreach ($footer_pages as $page) {
    $page['parent_id']
        ? $temp['children'][$page['parent_id']][] = $page
        : $temp['parents'][$page['id']] = $page;
}

// Convert to final structure using array_map (functional approach)
$footer_menus = array_values(array_filter(array_map(
    fn($id, $parent) => isset($temp['children'][$id])
    ? ['parent' => $parent, 'children' => $temp['children'][$id]]
    : null,
    array_keys($temp['parents']),
    $temp['parents']
)));

?>

<?php if (($footer ?? true) !== false) { ?>
<?php
// ============================================================================
// FOOTER
// Brand and contact, the CMS menus, the ways to follow and to get the app,
// then a bottom bar with the legal links and the trust marks. Component
// classes ft-* live in includes/footer/styles.php; the data comes from the
// settings ($GLOBALS['app']) and the CMS footer menus above.
// ============================================================================
require views . 'includes/footer/styles.php';

$ftApp = $GLOBALS['app'] ?? [];
$ftName = (string) ($ftApp['business_name'] ?? '');
$ftAddress = implode(', ', array_filter([
    trim((string) ($ftApp['address'] ?? '')),
    trim((string) ($ftApp['city'] ?? '')),
    trim(((string) ($ftApp['state'] ?? '')) . ' ' . ((string) ($ftApp['post_code'] ?? ''))),
    trim((string) ($ftApp['country_name'] ?? '')),
]));
$ftPhone = trim((string) ($ftApp['contact_phone'] ?? ''));
$ftEmail = trim((string) ($ftApp['contact_email'] ?? ''));
$ftSocial = json_decode((string) ($ftApp['social_media'] ?? '{}'), true);
$ftSocial = is_array($ftSocial) ? array_filter(array_map('trim', array_map('strval', $ftSocial))) : [];
// "We accept": PayPal when the PayPal gateway is on; Visa and Mastercard when a card
// gateway is on (any active gateway that is not PayPal, wallet, credits or offline).
$ftPay = [];
try {
    $ftGateways = array_map('strtolower', array_map('strval', (array) $db->select('payment_gateways', 'name', ['status' => 1])));
    $ftNoCards = ['paypal', 'wire transfer', 'pay later', 'wallet balance', 'credits', 'mpesa', 'coinsbuy'];
    if (array_diff($ftGateways, $ftNoCards)) {
        $ftPay[] = 'visa';
        $ftPay[] = 'mastercard';
    }
    if (in_array('paypal', $ftGateways, true)) {
        $ftPay[] = 'paypal';
    }
} catch (\Throwable $e) {
    $ftPay = [];
}
$ftPayLogos = [
    'visa'       => ['Visa', '<svg viewBox="0 0 48 16" aria-hidden="true"><path fill="#1a1f71" d="M20.8 15.7h-3.9L19.4.3h3.9l-2.5 15.4zM35 .7a9.6 9.6 0 0 0-3.5-.6c-3.8 0-6.5 2-6.6 4.9 0 2.1 1.9 3.3 3.4 4 1.5.8 2 1.2 2 1.9 0 1-1.2 1.4-2.3 1.4-1.5 0-2.4-.2-3.6-.8l-.5-.2-.6 3.3c.9.4 2.6.8 4.3.8 4 0 6.7-2 6.7-5 0-1.7-1-3-3.3-4-1.4-.7-2.2-1.1-2.2-1.8 0-.6.7-1.3 2.2-1.3 1.2 0 2.2.3 2.9.6l.3.2L35 .7zM45 .3h-3c-.9 0-1.6.3-2 1.2l-5.8 14.2h4.1l.8-2.3h5l.5 2.3H48L45 .3zm-4.8 10 1.6-4.3.5-1.4.3 1.3.9 4.4h-3.3zM13.6.3 9.8 10.8l-.4-2.1C8.7 6.3 6.5 3.7 4 2.4l3.5 13.3h4.1L17.8.3h-4.2z"/><path fill="#f7b600" d="M6.3.3H.1L0 .6c4.9 1.3 8.1 4.3 9.4 8L8.1 1.5C7.8.6 7.2.3 6.3.3z"/></svg>'],
    'mastercard' => ['Mastercard', '<svg viewBox="0 0 32 20" aria-hidden="true"><circle cx="10" cy="10" r="10" fill="#eb001b"/><circle cx="22" cy="10" r="10" fill="#f79e1b"/><path fill="#ff5f00" d="M16 2a10 10 0 0 1 0 16 10 10 0 0 1 0-16z"/></svg>'],
    'paypal'     => ['PayPal', '<svg viewBox="0 0 24 28" aria-hidden="true"><path fill="#003087" d="M19.6 2.1C18.4.7 16.2 0 13.4 0H5.2c-.6 0-1 .4-1.1 1L.7 22.7c-.1.4.3.8.7.8h5.1l1.3-8.1v.3c.1-.6.6-1 1.1-1h2.3c4.6 0 8.2-1.9 9.3-7.3v-.5c.2-2-.1-3.4-1.2-4.6"/><path fill="#009cde" d="M20.8 6.7c-1.1 5.3-4.6 7.2-9.2 7.2H9.3c-.6 0-1 .4-1.1 1L6.6 25.2c-.1.4.2.7.6.7h4.1c.5 0 .9-.4 1-.9v-.2l.8-5v-.3c.1-.5.5-.9 1-.9h.6c4 0 7.1-1.6 8-6.3.4-2 .2-3.6-.8-4.8l-1.1-.8"/></svg>'],
];
$ftIos = (($ftApp['show_apps'] ?? '1') == '1') ? trim((string) ($ftApp['ios_store'] ?? '')) : '';
$ftAndroid = (($ftApp['show_apps'] ?? '1') == '1') ? trim((string) ($ftApp['android_store'] ?? '')) : '';
$ftMenus = array_slice($footer_menus, 0, 3);

$ftIcon = [
    'pin'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
    'phone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>',
    'mail'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
    'headset' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14v-3a8 8 0 0 1 16 0v3"/><path d="M4 14h3v5H5a1 1 0 0 1-1-1zM17 14h3v4a1 1 0 0 1-1 1h-2zM15 21h-3"/></svg>',
    'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>',
    'card'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/></svg>',
    'tag'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
    'mark'  => '<svg viewBox="0 0 700 700" fill="currentColor" aria-hidden="true"><g transform="translate(0,700) scale(0.1,-0.1)"><path d="M4435 5753 c-307 -19 -533 -75 -848 -209 l-156 -66 -78 45 c-146 86 -349 156 -563 193 -115 20 -360 23 -445 5 -141 -29 -285 -91 -330 -141 -42 -46 -7 -157 90 -288 l44 -60 68 34 c112 55 204 74 367 74 87 0 164 -6 202 -15 74 -17 174 -54 174 -64 0 -8 -171 -70 -275 -100 -320 -91 -556 -73 -658 50 -54 67 -97 165 -97 222 0 60 -12 54 -40 -21 -28 -73 -28 -158 -2 -278 43 -196 136 -378 224 -443 94 -69 244 -98 431 -81 317 28 432 73 1177 461 489 255 824 365 1145 376 157 6 237 -7 327 -53 72 -36 153 -116 185 -183 25 -49 28 -67 27 -146 0 -105 -22 -172 -89 -275 -65 -101 -174 -196 -415 -361 -188 -129 -664 -429 -682 -429 -4 0 -8 19 -8 43 0 107 -57 347 -126 536 -30 82 -175 390 -202 429 -6 10 -59 -13 -218 -94 -115 -59 -210 -108 -212 -110 -2 -1 14 -33 35 -71 134 -235 217 -551 248 -944 l6 -76 -138 -78 c-245 -139 -511 -317 -803 -538 -172 -131 -243 -191 -368 -312 -275 -265 -473 -617 -489 -865 -4 -75 -2 -94 22 -160 66 -182 187 -375 300 -478 54 -49 138 -103 182 -116 20 -7 20 -3 -13 61 -123 241 -21 584 288 969 189 236 545 536 869 732 789 479 1159 721 1514 987 267 200 412 385 494 630 107 320 19 641 -248 905 -148 146 -286 223 -484 270 -75 18 -310 43 -361 38 -12 -1 -43 -3 -71 -5z"/><path d="M3955 2994 c-154 -91 -285 -171 -291 -177 -7 -7 -24 -50 -39 -97 -85 -260 -216 -498 -365 -662 -116 -128 -345 -305 -490 -378 -30 -15 -91 -40 -135 -55 -44 -16 -85 -32 -91 -37 -19 -14 -36 -88 -35 -153 1 -114 63 -232 148 -282 37 -21 56 -26 109 -24 121 3 264 51 412 138 294 172 642 576 832 968 100 206 159 392 200 633 19 114 37 292 29 292 -2 -1 -130 -75 -284 -166z"/></g></svg>',
    'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5l2.2 1.9 2.9-.3 1 2.7 2.6 1.4-.6 2.8 1.6 2.4-1.9 2.2.3 2.9-2.7 1-1.4 2.6-2.8-.6L12 22.5l-2.4-1.6-2.8.6-1.4-2.6-2.7-1 .3-2.9L1.1 12l1.6-2.4-.6-2.8 2.6-1.4 1-2.7 2.9.3z"/><path d="m9 12 2 2 4-4"/></svg>',
    'up'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
];
$ftSocialIcons = [
    'facebook'  => ['Facebook',  '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
    'twitter'   => ['X',         '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
    'instagram' => ['Instagram', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>'],
    'youtube'   => ['YouTube',   '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'],
    'linkedin'  => ['LinkedIn',  '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z"/></svg>'],
    'tiktok'    => ['TikTok',    '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.53.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'],
    'snapchat'  => ['Snapchat',  '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.206.793c.99 0 4.347.276 5.93 3.821.529 1.193.403 3.219.299 4.847l-.003.06c-.012.18-.022.345-.03.51.075.045.203.09.401.09.3-.016.659-.12 1.033-.301a.32.32 0 0 1 .114-.023c.135 0 .25.04.341.108.135.09.225.24.225.39 0 .21-.12.389-.36.524-.12.06-.24.12-.42.18-.06.016-.15.045-.239.075-.42.135-1.065.345-1.245.855-.09.24-.045.54.135.885v.015c.06.12 1.425 3.165 4.44 3.66.24.045.405.24.39.48 0 .06-.015.12-.045.18-.209.495-1.11.855-2.759 1.095-.06.09-.12.36-.165.555-.03.165-.075.345-.12.525-.06.24-.255.39-.495.39h-.03c-.12 0-.285-.03-.48-.06-.36-.075-.81-.135-1.38-.135-.33 0-.66.015-1.005.075-.69.12-1.275.54-1.95 1.02-.96.675-2.04 1.44-3.69 1.44h-.135c-1.65 0-2.73-.765-3.69-1.44-.675-.48-1.26-.9-1.95-1.02a6.4 6.4 0 0 0-1.005-.075c-.585 0-1.035.075-1.38.15-.18.03-.36.06-.48.06-.27 0-.465-.15-.51-.39-.045-.18-.09-.36-.12-.525-.045-.195-.105-.48-.165-.555-1.65-.24-2.55-.6-2.76-1.095a.48.48 0 0 1-.045-.18c-.015-.24.15-.435.39-.48 3.015-.495 4.38-3.54 4.44-3.66v-.015c.18-.345.225-.645.135-.885-.18-.51-.825-.72-1.245-.855-.09-.03-.18-.06-.24-.075-.735-.285-.84-.6-.795-.825.06-.3.42-.51.72-.51.09 0 .165.015.225.045.39.18.735.285 1.035.285.24 0 .375-.06.435-.09l-.03-.51-.003-.06c-.105-1.628-.23-3.654.299-4.847C7.86 1.069 11.215.793 12.206.793z"/></svg>'],
    'whatsapp'  => ['WhatsApp',  '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>'],
];
?>
<footer class="ft">
  <div class="container">
    <div class="ft-top">

      <!-- brand and contact -->
      <div class="ft-brand">
        <a href="<?= root ?>" aria-label="<?= htmlspecialchars($ftName) ?>">
          <?php // a light logo for the dark ground when there is one (uploads/global/logo-light.png); else the logo is turned white ?>
          <?php $ftLogoLight = is_file(dirname(__DIR__, 3) . '/uploads/global/logo-light.png'); ?>
          <img src="<?= versionedAssetUrl($ftLogoLight ? 'uploads/global/logo-light.png' : 'uploads/global/logo.png') ?>" alt="<?= htmlspecialchars($ftName) ?>" width="160" height="40" decoding="async"<?= $ftLogoLight ? '' : ' class="is-inverted"' ?>>
        </a>
        <p class="ft-tagline"><?= htmlspecialchars((string) T::footer_trusted_partner) ?></p>
        <ul class="ft-contact">
          <?php if ($ftAddress !== ''): ?>
          <li><span><?= $ftIcon['pin'] ?></span><span><?= htmlspecialchars($ftAddress) ?></span></li>
          <?php endif; ?>
          <?php if ($ftPhone !== ''): ?>
          <li><span><?= $ftIcon['phone'] ?></span><a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $ftPhone)) ?>"><?= htmlspecialchars($ftPhone) ?></a></li>
          <?php endif; ?>
          <?php if ($ftEmail !== ''): ?>
          <li><span><?= $ftIcon['mail'] ?></span><a href="mailto:<?= htmlspecialchars($ftEmail) ?>"><?= htmlspecialchars($ftEmail) ?></a></li>
          <?php endif; ?>
        </ul>
        <?php if (!empty($ftApp['site_offline'])): ?>
        <p class="ft-tagline" style="color:#b91c1c"><?= htmlspecialchars((string) T::footer_currently_offline) ?> <?= htmlspecialchars((string) ($ftApp['offline_message'] ?? '')) ?></p>
        <?php endif; ?>
      </div>

      <!-- the menus of the CMS -->
      <div class="ft-menus" style="--ft-cols:<?= max(1, count($ftMenus)) ?>">
        <?php foreach ($ftMenus as $ftMenu): ?>
        <div>
          <h4 class="ft-h"><?= htmlspecialchars(getTranslatedPageName($ftMenu['parent'], $currentLang)) ?></h4>
          <ul class="ft-menu">
            <?php foreach ($ftMenu['children'] as $ftChild): ?>
            <li><a href="<?= root ?>page/<?= htmlspecialchars($ftChild['slug_url']) ?>"><?= htmlspecialchars(getTranslatedPageName($ftChild, $currentLang)) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- follow, the app, support -->
      <div class="ft-side">
        <div>
          <!-- newsletter sign-up (app/routes/newsletterRoutes.php) -->
          <h4 class="ft-h"><?= htmlspecialchars((string) T::newsletter) ?></h4>
          <form class="ft-news" action="<?= root ?>newsletter/subscribe" method="post" novalidate>
            <p><?= htmlspecialchars((string) T::newsletter_lead) ?></p>
            <div class="ft-news-row">
              <input type="email" name="email" required maxlength="190" autocomplete="email"
                     placeholder="<?= htmlspecialchars((string) T::email_address) ?>" aria-label="<?= htmlspecialchars((string) T::email_address) ?>">
              <input type="text" name="website" tabindex="-1" autocomplete="off" class="ft-news-hp" aria-hidden="true">
              <button type="submit"><?= htmlspecialchars((string) T::subscribe) ?></button>
            </div>
            <p class="ft-news-msg" role="status" aria-live="polite"></p>
          </form>
          <?php if ($ftSocial): ?>
          <h4 class="ft-h"><?= htmlspecialchars((string) T::follow_us) ?></h4>
          <div class="ft-social">
            <?php foreach ($ftSocialIcons as $ftKey => [$ftLabel, $ftSvg]): if (empty($ftSocial[$ftKey])) continue; ?>
            <a href="<?= htmlspecialchars($ftSocial[$ftKey]) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $ftLabel ?>"><?= $ftSvg ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($ftIos !== '' || $ftAndroid !== ''): ?>
          <h4 class="ft-h"><?= htmlspecialchars((string) T::mobile_apps) ?></h4>
          <div class="ft-apps">
            <?php if ($ftIos !== ''): ?>
            <a class="ft-app" href="<?= htmlspecialchars($ftIos) ?>" target="_blank" rel="noopener noreferrer">
              <svg viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
              <span><small><?= htmlspecialchars((string) T::download_on_the) ?></small><b>App Store</b></span>
            </a>
            <?php endif; ?>
            <?php if ($ftAndroid !== ''): ?>
            <a class="ft-app" href="<?= htmlspecialchars($ftAndroid) ?>" target="_blank" rel="noopener noreferrer">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.609 1.814 13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm10.89 10.893 2.302 2.302-10.937 6.333 8.635-8.635zm3.199-3.198 2.807 1.626a1 1 0 0 1 0 1.73l-2.808 1.626L15.206 12l2.492-2.491zM5.864 2.658 16.8 8.99l-2.302 2.302-8.635-8.635z"/></svg>
              <span><small><?= htmlspecialchars((string) T::get_it_on) ?></small><b>Google Play</b></span>
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="ft-support">
          <span><?= $ftIcon['headset'] ?></span>
          <span><b><?= htmlspecialchars((string) T::footer_support_24_7) ?></b><small><?= htmlspecialchars((string) T::footer_always_here) ?></small></span>
        </div>
      </div>

    </div>
  </div>

  <!-- trust marks -->
  <div class="ft-strip">
    <div class="container">
      <span class="ft-mark"><span><?= $ftIcon['shield'] ?></span><span><?= htmlspecialchars((string) T::ssl_secure) ?></span></span>
      <a class="ft-mark" href="https://iata.co" target="_blank" rel="noopener"><span><?= $ftIcon['check'] ?></span><span>IATA</span></a>
      <span class="ft-mark"><span><?= $ftIcon['card'] ?></span><span><?= htmlspecialchars((string) T::secure_payments) ?></span></span>
      <span class="ft-mark"><span><?= $ftIcon['tag'] ?></span><span><?= htmlspecialchars((string) T::best_price_guaranteed) ?></span></span>
      <?php if ($ftPay): ?>
      <span class="ft-pay">
        <small><?= htmlspecialchars((string) T::we_accept) ?></small>
        <?php foreach ($ftPay as $ftPayKey): ?><span class="ft-pay-logo" title="<?= $ftPayLogos[$ftPayKey][0] ?>"><?= $ftPayLogos[$ftPayKey][1] ?></span><?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- bottom bar: copyright | legal links | powered by -->
  <div class="ft-bottom">
    <div class="container">
      <p class="ft-copy">© <?= date('Y') ?> <b><?= htmlspecialchars($ftName) ?></b>. <?= htmlspecialchars((string) T::rights_reserved) ?></p>
      <ul class="ft-legal">
        <li><a href="<?= root ?>page/privacy-policy"><?= htmlspecialchars((string) T::privacy_policy) ?></a></li>
        <li><a href="<?= root ?>page/terms-of-use"><?= htmlspecialchars((string) T::terms_of_use) ?></a></li>
        <li><a href="<?= root ?>page/cookies-policy"><?= htmlspecialchars((string) T::cookies_policy) ?></a></li>
        <li><a href="<?= root ?>page/refund-policy"><?= htmlspecialchars((string) T::refund_policy) ?></a></li>
      </ul>
      <?php // POWERED-BY LINKBACK — only a licence with remove_linkback may drop it; a dev host (localhost) always shows it ?>
      <?php if (!function_exists('plan_allows') || !plan_allows('remove_linkback') || (function_exists('is_dev_host') && is_dev_host())): ?>
      <a class="ft-powered" href="https://phptravels.com" target="_blank" rel="noopener" title="PHPTRAVELS"><i></i><span><?= htmlspecialchars((string) T::powered_by) ?></span><?= $ftIcon['mark'] ?><b>PHPTRAVELS</b></a>
      <?php else: ?>
      <span></span>
      <?php endif; ?>
    </div>
  </div>
  <script data-cfasync="false">
    // newsletter: send the form without leaving the page, show the answer under it
    (function () {
      var form = document.querySelector('.ft-news');
      if (!form) return;
      var msg = form.querySelector('.ft-news-msg'), btn = form.querySelector('button');
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = form.email.value.trim();
        msg.className = 'ft-news-msg';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          msg.textContent = <?= json_encode((string) T::newsletter_invalid, JSON_UNESCAPED_UNICODE) ?>;
          msg.classList.add('is-error');
          return;
        }
        btn.disabled = true;
        fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email, website: form.website.value }) })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            msg.textContent = d.message || '';
            msg.classList.add(d.status === 'success' ? 'is-ok' : 'is-error');
            if (d.status === 'success') form.email.value = '';
          })
          .catch(function () { msg.textContent = <?= json_encode((string) T::newsletter_invalid, JSON_UNESCAPED_UNICODE) ?>; msg.classList.add('is-error'); })
          .then(function () { btn.disabled = false; });
      });
    })();
  </script>
  <!-- back to top -->
  <button type="button" class="ft-top-btn" id="backToTop" aria-label="Back to top" onclick="window.scrollTo({ top: 0, behavior: 'smooth' })"><?= $ftIcon['up'] ?></button>
  <script data-cfasync="false">
    // the button shows after 300px of scroll; it moves up over a booking bar or the filter button of a listing
    (function () {
      var btn = document.getElementById('backToTop');
      if (!btn) return;
      var queued = false;
      var update = function () {
        btn.classList.toggle('is-on', window.pageYOffset > 300);
        var bar = document.querySelector('.room-booking-bar');
        var filter = document.querySelector('.mobile-filter-btn');
        if (bar && getComputedStyle(bar).display !== 'none') btn.style.bottom = ((bar.offsetHeight || 80) + 16) + 'px';
        else if (filter && getComputedStyle(filter).display !== 'none' && window.innerWidth < 768) btn.style.bottom = '80px';
        else btn.style.bottom = '';
        queued = false;
      };
      window.addEventListener('scroll', function () { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
    })();
  </script>
</footer>
<?php } ?>

<?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
        <script defer src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
        <script defer>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Pusher === 'undefined') { console.warn('Pusher script not loaded, skipping init.'); return; }
                if (!window.__appPusherInit) {
                    window.__appPusherInit = true;
                    let notificationCounter = 1;
                    let processedNotifications = new Set();

                    const generateSessionId = () => {
                        let sessionId = localStorage.getItem('pusher_session_id');
                        if (!sessionId) {
                            sessionId = 'user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                            localStorage.setItem('pusher_session_id', sessionId);
                        }
                        return sessionId;
                    };

                    const sessionId = generateSessionId();

                    const pusher = new Pusher("47c534ae27bce2c4a8b7", {
                        cluster: "us2"
                    });

                    const generalChannel = pusher.subscribe("notify-channel");

                    generalChannel.bind("new-notification", function (data) {
                        console.log('General channel notification:', data);

                        if (!data.is_test && Notification.permission === "granted") {
                            showNotification(data.title, data.message, data);
                        }
                    });

                    function showNotification(title, message, data = {}) {
                        if (Notification.permission === "granted") {
                            const notificationId = title + '_' + message + '_' + Date.now();

                            if (processedNotifications.has(notificationId)) {
                                console.log('⚠️ Duplicate notification skipped:', notificationId);
                                return false;
                            }

                            processedNotifications.add(notificationId);

                            setTimeout(() => {
                                processedNotifications.delete(notificationId);
                            }, 5000);

                            const uniqueTag = 'notification-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

                            const notification = new Notification(title, {
                                body: message,
                                // icon: data.icon || '/uploads/global/logo.png',
                                // badge: data.badge || '/uploads/global/logo.png',
                                tag: uniqueTag,
                                requireInteraction: false,
                                silent: false,
                                timestamp: Date.now()
                            });

                            notification.onclick = function () {
                                console.log('Notification clicked:', uniqueTag);

                                const redirectUrl = data.url || window.location.href;
                                window.open(redirectUrl, "_blank");
                                window.focus();
                                notification.close();
                            };

                            notification.onclose = function () {
                                console.log('Notification closed:', uniqueTag);
                            };

                            notification.onshow = function () {
                                console.log('✅ Notification displayed:', uniqueTag);
                            };

                            return true;
                        }
                        return false;
                    }

                    async function requestNotificationPermission() {
                        if (!("Notification" in window)) {
                            return "unsupported";
                        }

                        if (Notification.permission === "granted") {
                            return "granted";
                        }

                        if (Notification.permission === "denied") {
                            return "denied";
                        }

                        try {
                            const permission = await Notification.requestPermission();
                            return permission;
                        } catch (error) {
                            console.error("Error requesting notification permission:", error);
                            return "denied";
                        }
                    }

                    async function ensureNotificationPermission() {
                        const permission = await requestNotificationPermission();

                        switch (permission) {
                            case "granted":
                                return { success: true, message: "Notifications are enabled" };

                            case "denied":
                                return {
                                    success: false,
                                    message: "Notifications are blocked. Please enable them in your browser settings."
                                };

                            case "unsupported":
                                return {
                                    success: false,
                                    message: "Your browser doesn't support notifications"
                                };

                            default:
                                return {
                                    success: false,
                                    message: "Notification permission not granted"
                                };
                        }
                    }

                    function getNotificationIcon() {
                        // Try multiple possible paths
                        const possiblePaths = [
                            '/uploads/global/logo.png',
                            '/assets/images/logo.png',
                            '/img/logo.png',
                            '/favicon.ico'
                        ];
                        return '';
                    }
                } // end window.__appPusherInit guard
            });
        </script>
<?php endif; ?>

<script defer src="<?= root ?>assets/js/toast.js"></script>
<script defer
    src="<?= root ?>assets/js/datepicker.js?v=<?= file_exists(__DIR__ . '/../../../assets/js/datepicker.js') ? filemtime(__DIR__ . '/../../../assets/js/datepicker.js') : time() ?>"></script>
<?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
        <script defer src="https://unpkg.com/vt-notifications@latest/dist/vt-notifications.umd.js"></script>
<?php endif; ?>

<!-- Demo Warning Modal - For phptravels.net and localhost only -->
<?php
try {
    require_once views . "includes/demo-warning-modal.php";
} catch (\Exception $e) {
    // Log error but don't break the page
    error_log("Demo Warning Modal Error: " . $e->getMessage());
    if (in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1', 'phptravels.net', 'www.phptravels.net'])) {
        echo "<!-- Demo Modal Error: " . htmlspecialchars($e->getMessage()) . " -->";
    }
}
?>

</body>

</html>
