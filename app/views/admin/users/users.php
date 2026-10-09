<div class="container my-4">

<!-- Success/Error Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
        <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php

// ?approval=pending lists only agents waiting for admin approval
$pendingAgentsOnly = ($_GET['approval'] ?? '') === 'pending';

// Wallet balances are stored in the system BASE currency (same assumption the
// edit page uses). Label the column with the base currency, NOT the user's
// preferred `currency` column — otherwise a USD value gets mislabelled (e.g. NGN).
$baseCurrency = (string) ($db->get('currencies', 'name', ['default' => 1]) ?: 'USD');

$usersCrud = crud()->table('users')
    ->col('id,first_name,last_name,email,role,agent_approved,balance')
    ->label([
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'role' => 'Role',
        'agent_approved' => 'Approval'])
    ->title($pendingAgentsOnly ? 'Agents Pending Approval' : 'Users')
    ->row([
        'balance' => htmlspecialchars($baseCurrency) . ' <strong>{{number_format(balance, 2)}}</strong>',
        // Agents who signed up wait here until an admin approves them.
        'agent_approved' => function ($row) {
            if (!agentPendingApproval($row)) {
                return strtolower((string) ($row['role'] ?? '')) === 'agent'
                    ? '<span class="inline-block px-2 py-1 rounded text-xs font-semibold uppercase bg-green-100 text-green-800 border border-green-300">Approved</span>'
                    : '<span class="text-slate-400">—</span>';
            }
            return '<form method="POST" action="' . root . admin . '/users/approve-agent" class="flex items-center gap-2"'
                . ' onsubmit="return confirm(\'Approve this agent? They will be able to log in and will receive an approval email.\')">'
                . CSRF::tokenField()
                . '<input type="hidden" name="user_id" value="' . htmlspecialchars((string) $row['user_id']) . '">'
                . '<span class="inline-block px-2 py-1 rounded text-xs font-semibold uppercase bg-orange-100 text-orange-800 border border-orange-300">Pending</span>'
                . '<button type="submit" class="btn btn-sm emerald">Approve</button>'
                . '</form>';
        },
    ])
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'banned' => true,
    ])
    ->action_urls([
        'add' => admin.'/users/add',
        'edit' => root.admin.'/users/edit/{user_id}',
    ])
    ->id_column('user_id')
    ->col_width('id', '0px')
    ->col_width('first_name', '120px')
    ->col_width('last_name', '120px')
    ->col_width('email', '150px')
    ->col_width('role', '50px')
    ->col_width('currency', '50px')
    ->col_width('agent_approved', '170px');

if ($pendingAgentsOnly) {
    $usersCrud->where(['role' => 'agent', 'agent_approved' => 0]);
}

echo $usersCrud->render();

?>

</div>