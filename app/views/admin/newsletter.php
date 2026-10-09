<?php
// ============================================================================
// ADMIN / NEWSLETTER
// The people who signed up with the footer form (app/routes/newsletterRoutes.php).
// Switch one off to stop sending to it, delete it, or export the active ones.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<div class="container my-4">

    <div class="flex flex-wrap items-center justify-end gap-3 mb-4">
        <a href="<?= root . admin ?>/newsletter/export" class="btn white">
            <span class="material-symbols-outlined mr-1 text-sm">download</span>
            <?= htmlspecialchars((string) T::export_csv) ?>
        </a>
    </div>

    <?php
    echo crud()->table('newsletter_subscribers')
        ->title(T::newsletter_subscribers)
        ->col('email,language,created_at')
        ->label([
            'email' => T::email,
            'language' => T::language,
            'created_at' => T::date,
        ])
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => false,
            'delete' => true,
            'status' => true,
        ])
        ->order('id', 'DESC')
        ->render();
    ?>

</div>
