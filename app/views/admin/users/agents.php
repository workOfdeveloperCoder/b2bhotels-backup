<?php
// ============================================================================
// ADMIN / USERS / AGENTS
// Users > Agent (admin/users?search_col=role&q=agent): the agencies as cards —
// who they are, how to reach them, wallet and credit, markup and the bookings
// they made. Search, status and country filter in the page; the status switch
// saves through the shared CRUD endpoint (POST /ajax, toggle_status).
// "Table view" (&view=table) keeps the plain users table.
// Styles: agents/styles.php (.ag-*).
// ============================================================================
@$SECURE or die('Access Denied!');

require __DIR__ . '/agents/styles.php';

$baseCurrency = (string) ($db->get('currencies', 'name', ['default' => 1]) ?: 'USD');

$agRows = $db->select('users', [
    'id', 'user_id', 'role', 'title', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'city', 'country',
    'avatar', 'status', 'banned', 'agent_approved', 'balance', 'credit_limits', 'apply_markup', 'markup_type',
    'markup_value', 'created_at', 'last_login',
], ['role' => 'agent', 'ORDER' => ['id' => 'DESC']]) ?: [];

// bookings per agent: all, and paid
$agBookings = [];
$agIds = array_values(array_filter(array_column($agRows, 'user_id')));
if ($agIds) {
    foreach ((array) $db->select('bookings', ['user_id', 'payment_status'], ['user_id' => $agIds]) as $b) {
        $k = (string) $b['user_id'];
        $agBookings[$k]['all'] = ($agBookings[$k]['all'] ?? 0) + 1;
        if (strtolower((string) $b['payment_status']) === 'paid') {
            $agBookings[$k]['paid'] = ($agBookings[$k]['paid'] ?? 0) + 1;
        }
    }
}

// the agency profile each agent fills in on the website (name, licence, place, logo)
$agAgencies = [];
if ($agIds) {
    foreach ((array) $db->select('agencies', ['user_id', 'agency_name', 'license_number', 'city', 'country', 'logo'], ['user_id' => $agIds]) as $ag) {
        $agAgencies[(string) $ag['user_id']] = $ag;
    }
}
$agFile = function ($path) {
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return is_file(dirname(__DIR__, 4) . '/' . ltrim($path, '/')) ? root . ltrim($path, '/') : '';
};

// avatar tints: one of a few soft pairs, the same one for the same agent every time
$agTints = [['#e0e7ff', '#3730a3'], ['#fef3c7', '#92400e'], ['#ede9fe', '#5b21b6'], ['#dcfce7', '#166534'], ['#ffe4e6', '#9f1239'], ['#e0f2fe', '#075985']];

$agents = [];
foreach ($agRows as $r) {
    $person = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $person = $person !== '' ? $person : (string) $r['email'];
    $agency = $agAgencies[(string) $r['user_id']] ?? [];
    $agencyName = trim((string) ($agency['agency_name'] ?? ''));
    // the card is the agency: its name on top, the person under it (no agency yet: the person)
    $name = $agencyName !== '' ? $agencyName : $person;
    $words = preg_split('/\s+/', $name) ?: [$name];
    $initials = strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1)));
    $pending = agentPendingApproval($r);
    $banned = (int) ($r['banned'] ?? 0) === 1;
    $active = in_array(strtolower((string) $r['status']), ['active', '1'], true);
    $state = $banned ? 'banned' : ($pending ? 'pending' : ($active ? 'active' : 'inactive'));
    $phone = trim((string) ($r['phone'] ?? ''));
    if ($phone !== '' && !empty($r['phone_country_code'])) {
        $phone = '+' . ltrim((string) $r['phone_country_code'], '+') . ' ' . $phone;
    }
    $avatar = $agFile($agency['logo'] ?? '') ?: $agFile($r['avatar'] ?? '');
    $city = trim((string) ($r['city'] ?? '')) ?: trim((string) ($agency['city'] ?? ''));
    $country = trim((string) ($r['country'] ?? '')) ?: trim((string) ($agency['country'] ?? ''));
    $markup = '';
    if ((int) ($r['apply_markup'] ?? 0) === 1 && (float) ($r['markup_value'] ?? 0) > 0) {
        $markup = strtolower((string) $r['markup_type']) === 'fixed'
            ? $baseCurrency . ' ' . number_format((float) $r['markup_value'], 2)
            : rtrim(rtrim(number_format((float) $r['markup_value'], 2), '0'), '.') . '%';
    }
    $agents[] = [
        'id' => (int) $r['id'],
        'user_id' => (string) $r['user_id'],
        'name' => $name,
        'person' => $agencyName !== '' ? $person : '',
        'license' => trim((string) ($agency['license_number'] ?? '')),
        'initials' => $initials,
        'tint' => $agTints[crc32((string) $r['user_id']) % count($agTints)],
        'avatar' => $avatar,
        'email' => (string) $r['email'],
        'phone' => $phone,
        'place' => implode(', ', array_filter([$city, $country])),
        'country' => $country,
        'state' => $state,
        'active' => $active,
        'balance' => (float) ($r['balance'] ?? 0),
        'credit' => (float) ($r['credit_limits'] ?? 0),
        'markup' => $markup,
        'bookings' => (int) ($agBookings[(string) $r['user_id']]['all'] ?? 0),
        'paid' => (int) ($agBookings[(string) $r['user_id']]['paid'] ?? 0),
        'joined' => !empty($r['created_at']) ? date('d M Y', strtotime((string) $r['created_at'])) : '',
        'last_login' => !empty($r['last_login']) ? date('d M Y', strtotime((string) $r['last_login'])) : '',
    ];
}

$agTotal = count($agents);
$agActive = count(array_filter($agents, fn($a) => $a['state'] === 'active'));
$agPending = count(array_filter($agents, fn($a) => $a['state'] === 'pending'));
$agBookingsAll = array_sum(array_column($agents, 'bookings'));
$agPaidAll = array_sum(array_column($agents, 'paid'));
$agWallets = array_sum(array_column($agents, 'balance'));
$agCountries = array_values(array_unique(array_filter(array_column($agents, 'country'))));
sort($agCountries);

$agStateLabel = ['active' => T::active, 'inactive' => T::inactive, 'pending' => 'Pending approval', 'banned' => 'Banned'];
$agStateClass = ['active' => 'is-on', 'inactive' => 'is-off', 'pending' => 'is-wait', 'banned' => 'is-ban'];
$agMoney = fn($v) => htmlspecialchars($baseCurrency) . ' ' . number_format((float) $v, 2);
?>
<div class="container my-4 ag" x-data="adminAgents(<?= htmlspecialchars(json_encode(array_map(fn($a) => [
    'id' => $a['id'], 'state' => $a['state'], 'country' => $a['country'],
    'text' => mb_strtolower($a['name'] . ' ' . $a['person'] . ' ' . $a['license'] . ' ' . $a['email'] . ' ' . $a['phone'] . ' ' . $a['place']),
], $agents), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">

  <?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- HEAD -->
  <div class="ag-head">
    <div>
      <p class="ag-crumbs"><a href="<?= root . admin ?>"><?= T::dashboard ?></a><span>/</span><a href="<?= root . admin ?>/users"><?= T::users ?></a><span>/</span><span>Agents</span></p>
      <h1 class="ag-title">Agents</h1>
      <p class="ag-lead">The travel agencies that book through your platform: contact, wallet, credit and bookings.</p>
    </div>
    <div class="ag-actions">
      <a class="ag-btn" href="<?= root . admin ?>/users?search_col=role&amp;q=agent&amp;view=table">
        <span class="material-symbols-outlined">table_rows</span> Table view
      </a>
      <a class="ag-btn is-primary" href="<?= root . admin ?>/users/add">
        <span class="material-symbols-outlined">add</span> New agent
      </a>
    </div>
  </div>

  <!-- KPIS -->
  <dl class="ag-kpis">
    <div class="ag-kpi">
      <div><dt>Total agents</dt><dd><?= $agTotal ?></dd><small><?= $agActive ?> active</small></div>
      <span class="ag-kpi-icon"><span class="material-symbols-outlined">groups</span></span>
    </div>
    <div class="ag-kpi">
      <div><dt>Pending approval</dt><dd><?= $agPending ?></dd><small>waiting to log in</small></div>
      <span class="ag-kpi-icon"><span class="material-symbols-outlined">hourglass_top</span></span>
    </div>
    <div class="ag-kpi">
      <div><dt>Bookings</dt><dd><?= $agBookingsAll ?></dd><small><?= $agPaidAll ?> paid</small></div>
      <span class="ag-kpi-icon"><span class="material-symbols-outlined">confirmation_number</span></span>
    </div>
    <div class="ag-kpi">
      <div><dt>Wallet balances</dt><dd><?= number_format($agWallets, 2) ?></dd><small><?= htmlspecialchars($baseCurrency) ?> held by agents</small></div>
      <span class="ag-kpi-icon"><span class="material-symbols-outlined">account_balance_wallet</span></span>
    </div>
  </dl>

  <!-- FILTERS -->
  <div class="ag-bar">
    <label class="ag-search">
      <span class="material-symbols-outlined">search</span>
      <input type="search" class="input" x-model="q" placeholder="Search name, email, phone, city…" aria-label="Search agents">
    </label>
    <select class="select ag-filter" x-model="state" aria-label="Status">
      <option value="">Status: All</option>
      <option value="active"><?= T::active ?></option>
      <option value="inactive"><?= T::inactive ?></option>
      <option value="pending">Pending approval</option>
      <option value="banned">Banned</option>
    </select>
    <?php if ($agCountries): ?>
    <select class="select ag-filter" x-model="country" aria-label="Country">
      <option value="">Country: All</option>
      <?php foreach ($agCountries as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <span class="ag-count"><span x-text="shown"><?= $agTotal ?></span> of <?= $agTotal ?> shown</span>
  </div>

  <!-- CARDS -->
  <div class="ag-grid">
    <?php foreach ($agents as $a): ?>
    <article class="ag-card" x-show="match(<?= $a['id'] ?>)">
      <div class="ag-card-top">
        <span class="ag-avatar" style="background: <?= $a['tint'][0] ?>; color: <?= $a['tint'][1] ?>;">
          <?php if ($a['avatar'] !== ''): ?><img src="<?= htmlspecialchars($a['avatar']) ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars($a['initials']) ?><?php endif; ?>
        </span>
        <div class="ag-who">
          <h2 class="ag-name"><a href="<?= root . admin ?>/users/edit/<?= urlencode($a['user_id']) ?>" title="<?= htmlspecialchars($a['name']) ?>"><?= htmlspecialchars($a['name']) ?></a></h2>
          <?php if ($a['person'] !== ''): ?><p class="ag-sub"><span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px">person</span> <?= htmlspecialchars($a['person']) ?></p><?php endif; ?>
          <?php if ($a['place'] !== ''): ?><p class="ag-sub"><span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px">location_on</span> <?= htmlspecialchars($a['place']) ?></p><?php endif; ?>
          <div class="ag-pills">
            <span class="ag-pill <?= $agStateClass[$a['state']] ?>" data-pill="<?= $a['id'] ?>"><i></i><span><?= htmlspecialchars((string) $agStateLabel[$a['state']]) ?></span></span>
          </div>
        </div>
        <?php if ($a['state'] === 'active' || $a['state'] === 'inactive'): ?>
        <label class="ag-switch" title="<?= T::status ?>">
          <input type="checkbox" <?= $a['active'] ? 'checked' : '' ?> @change="toggle($event, <?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['user_id']), ENT_QUOTES) ?>)" aria-label="<?= T::status ?>">
          <span></span>
        </label>
        <?php endif; ?>
      </div>

      <ul class="ag-rows">
        <li><span class="material-symbols-outlined">mail</span><span><a href="mailto:<?= htmlspecialchars($a['email']) ?>"><?= htmlspecialchars($a['email']) ?></a></span></li>
        <li><span class="material-symbols-outlined">call</span><span class="<?= $a['phone'] === '' ? 'is-none' : '' ?>"><?= $a['phone'] !== '' ? '<a href="tel:' . htmlspecialchars(preg_replace('/[^\d+]/', '', $a['phone'])) . '">' . htmlspecialchars($a['phone']) . '</a>' : 'No phone' ?></span></li>
        <?php if ($a['license'] !== ''): ?><li><span class="material-symbols-outlined">verified</span><span>Licence <?= htmlspecialchars($a['license']) ?></span></li><?php endif; ?>
        <li><span class="material-symbols-outlined">calendar_today</span><span>Joined <?= htmlspecialchars($a['joined'] ?: '—') ?><?= $a['last_login'] !== '' ? ' · last login ' . htmlspecialchars($a['last_login']) : '' ?></span></li>
      </ul>

      <dl class="ag-facts">
        <div class="ag-fact"><dt>Wallet</dt><dd><?= $agMoney($a['balance']) ?></dd></div>
        <div class="ag-fact"><dt>Credit limit</dt><dd><?= $a['credit'] > 0 ? $agMoney($a['credit']) : '<small>None</small>' ?></dd></div>
        <div class="ag-fact"><dt>Markup</dt><dd><?= $a['markup'] !== '' ? htmlspecialchars($a['markup']) : '<small>Default</small>' ?></dd></div>
        <div class="ag-fact"><dt>Bookings</dt><dd><?= $a['bookings'] ?> <small><?= $a['paid'] ?> paid</small></dd></div>
      </dl>

      <div class="ag-card-foot">
        <?php if ($a['state'] === 'pending'): ?>
        <form method="POST" action="<?= root . admin ?>/users/approve-agent" onsubmit="return confirm('Approve this agent? They will be able to log in and will receive an approval email.')">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="user_id" value="<?= htmlspecialchars($a['user_id']) ?>">
          <button type="submit" class="ag-act is-approve"><span class="material-symbols-outlined">check</span> Approve</button>
        </form>
        <?php endif; ?>
        <a class="ag-act" href="<?= root . admin ?>/users/manage-funds/<?= urlencode($a['user_id']) ?>"><span class="material-symbols-outlined">account_balance_wallet</span> Funds</a>
        <a class="ag-act is-primary is-end" href="<?= root . admin ?>/users/edit/<?= urlencode($a['user_id']) ?>"><span class="material-symbols-outlined">edit</span> <?= T::edit ?></a>
      </div>
    </article>
    <?php endforeach; ?>

    <div class="ag-empty" x-show="shown === 0" <?= $agTotal ? 'x-cloak' : '' ?>>
      <span class="material-symbols-outlined">person_search</span>
      <?= $agTotal ? 'No agent matches these filters.' : 'No agents yet. Agents appear here when they sign up or when you add one.' ?>
    </div>
  </div>
</div>

<script data-cfasync="false">
function adminAgents(list) {
    return {
        list, q: '', state: '', country: '',
        get shown() { return this.list.filter(a => this.fits(a)).length; },
        fits(a) {
            const q = this.q.trim().toLowerCase();
            return (!q || a.text.includes(q)) && (!this.state || a.state === this.state) && (!this.country || a.country === this.country);
        },
        match(id) { const a = this.list.find(x => x.id === id); return a ? this.fits(a) : false; },
        // status switch: saved by the shared CRUD endpoint (users rows are keyed by user_id there);
        // the pill follows, a failure puts the switch back
        toggle(e, id, userId) {
            const box = e.target, on = box.checked;
            box.disabled = true;
            fetch('<?= root ?>ajax', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'toggle_status', table: 'users', column: 'status', id: userId, status: on ? 1 : 0 }),
            })
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') throw new Error(d.message || 'Failed');
                    const a = this.list.find(x => x.id === id); if (a) a.state = on ? 'active' : 'inactive';
                    const pill = document.querySelector('[data-pill="' + id + '"]');
                    if (pill) { pill.className = 'ag-pill ' + (on ? 'is-on' : 'is-off'); pill.lastElementChild.textContent = on ? <?= json_encode((string) T::active) ?> : <?= json_encode((string) T::inactive) ?>; }
                    window.vt && vt.success('Status updated');
                })
                .catch(err => { box.checked = !on; window.vt && vt.error(err.message); })
                .then(() => { box.disabled = false; });
        },
    };
}
</script>
