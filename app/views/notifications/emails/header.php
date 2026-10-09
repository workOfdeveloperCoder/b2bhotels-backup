<?php
/**
 * EMAIL HEADER
 * Every style that matters is written on the element: the Gmail app shows a mail of
 * a non-Google account without its <style> block, so a logo sized by a class came
 * out at its full size and the footer had no colours. The <style> block only adds
 * the font and the phone widths for the clients that read it.
 * Tables, not divs, for the frame: every mail client lays them out the same way.
 */
date_default_timezone_set('Asia/Karachi');
$rootUrl = defined('root') ? root : 'http://localhost/v10/';
$logoUrl = $rootUrl . 'uploads/global/logo.png';
$displayBrand = $companyName ?? getBrandName($settings ?? null);
$language = $language ?: 'en';
$emailFont = "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title><?= htmlspecialchars($displayBrand) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { margin: 0; padding: 0; }
        img { border: 0; outline: none; text-decoration: none; }
        table { border-collapse: collapse; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; border-radius: 0 !important; }
            .email-pad { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; background-color: #f1f5f9; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 32px 10px;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" border="0" align="center"
                       style="width: 600px; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; border-collapse: separate; overflow: hidden; text-align: left; font-family: <?= $emailFont ?>; color: #1e293b;">
                    <!-- Global Header -->
                    <tr>
                        <td class="email-pad" style="padding: 22px 32px; border-bottom: 1px solid #f1f5f9;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td align="left" valign="middle" style="text-align: left;">
                                        <a href="<?= htmlspecialchars($rootUrl) ?>" style="text-decoration: none;">
                                            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($displayBrand) ?>" height="32"
                                                 style="display: block; height: 32px; max-height: 32px; width: auto; max-width: 200px; border: 0;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle" style="text-align: right; font-family: <?= $emailFont ?>;">
                                        <div style="font-size: 15px; font-weight: 700; color: #0f172a; line-height: 1.3;">#<?= htmlspecialchars((string) ($invoiceId ?? 'xxxxxx')) ?></div>
                                        <div style="font-size: 12px; color: #64748b; line-height: 1.3; margin-top: 2px;"><?= date('D, M d, Y - h:i A') ?></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body of the mail -->
                    <tr>
                        <td style="padding: 0; text-align: left; font-family: <?= $emailFont ?>; font-size: 14px; line-height: 1.6; color: #1e293b;">
