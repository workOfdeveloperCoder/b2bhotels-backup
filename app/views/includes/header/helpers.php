<?php
// ============================================================================
// HEADER / HELPERS
// Small render functions shared by the header components, so a menu row looks
// the same wherever it is used (desktop dropdown, account menu, mobile sheet).
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('headerMenuItem')) {
  /**
   * One row inside a .hd-menu panel.
   *   label    text (required)
   *   href     link target (required)
   *   icon     line icon name from headerIcon()
   *   symbol   Material Symbol name (used for module icons coming from the database)
   *   meta     small text on the right, e.g. a currency code
   *   active   highlight as the current choice
   *   danger   red row (logout)
   *   external open in a new tab
   */
  function headerMenuItem(array $item): string {
    $class = 'hd-menu-item'
           . (!empty($item['active']) ? ' is-active' : '')
           . (!empty($item['danger']) ? ' is-danger' : '');

    $icon = '';
    if (!empty($item['symbol'])) {
      $icon = '<span class="material-symbols-outlined">' . htmlspecialchars((string) $item['symbol']) . '</span>';
    } elseif (!empty($item['icon'])) {
      $icon = headerIcon((string) $item['icon'], 16);
    }

    return '<a href="' . htmlspecialchars((string) $item['href']) . '" class="' . $class . '"'
         . (!empty($item['external']) ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
         . $icon
         . '<span class="hd-menu-text">' . htmlspecialchars((string) $item['label']) . '</span>'
         . (isset($item['meta']) && $item['meta'] !== '' ? '<span class="hd-menu-meta">' . htmlspecialchars((string) $item['meta']) . '</span>' : '')
         . '</a>';
  }
}

if (!function_exists('headerSpinner')) {
  /** Shown inside a panel while its list is being fetched. */
  function headerSpinner(): string {
    return '<div class="hd-spinner"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
         . '<circle cx="12" cy="12" r="10" stroke="#e5e5e5" stroke-width="2"></circle>'
         . '<path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg></div>';
  }
}

if (!function_exists('headerAccountItems')) {
  /** Links of the signed-in account menu (desktop dropdown + mobile sheet). */
  function headerAccountItems(array $hd): array {
    $items = [
      ['label' => T::dashboard,   'href' => root . 'dashboard', 'icon' => 'dashboard'],
      ['label' => T::profile,     'href' => root . 'profile',   'icon' => 'user'],
      ['label' => T::my_bookings, 'href' => root . 'bookings',  'icon' => 'calendar'],
    ];
    if (!empty($hd['isAdmin'])) {
      $items[] = ['label' => T::settings,        'href' => root . admin . '/settings',         'icon' => 'settings'];
      $items[] = ['label' => T::change_password, 'href' => root . 'profile#change-password', 'icon' => 'key'];
    }
    return $items;
  }
}

if (!function_exists('headerSignupItems')) {
  /** Signup choices when agents can register too. */
  function headerSignupItems(): array {
    return [
      ['label' => T::customer_signup, 'href' => root . 'signup',       'icon' => 'user'],
      ['label' => T::agent_signup,    'href' => root . 'agent-signup', 'icon' => 'briefcase'],
    ];
  }
}
