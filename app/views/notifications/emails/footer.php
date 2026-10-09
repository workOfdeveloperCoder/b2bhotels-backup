<?php
/**
 * EMAIL FOOTER
 * Closes the body cell opened by header.php, then the dark footer with the ways to
 * reach support. Every style is on the element (see header.php).
 */
global $db;
// Dynamically load support contact info from settings if not already available
if (empty($supportEmail) || empty($companyName)) {
    $footerSettings = $db->get('settings', ['contact_email', 'contact_phone', 'business_name'], ['id' => 1]);
    if (empty($supportEmail))  $supportEmail  = $footerSettings['contact_email']  ?? '';
    if (empty($supportPhone))  $supportPhone  = $footerSettings['contact_phone']  ?? '';
    if (empty($companyName))   $companyName   = getBrandName($footerSettings ?? null);
}
$language = $language ?: 'en';
$emailFont = $emailFont ?? "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
?>
                        </td>
                    </tr>
                    <!-- Global Footer -->
                    <tr>
                        <td class="email-pad" style="padding: 28px 32px; background-color: #0f172a; text-align: left; font-family: <?= $emailFont ?>; font-size: 13px; line-height: 1.6; color: #94a3b8;">
                            <div style="font-size: 12px; font-weight: 700; color: #ffffff; letter-spacing: 0.08em; text-transform: uppercase; margin: 0 0 8px;"><?= htmlspecialchars(translateTo('get_in_touch', $language)) ?></div>
                            <div style="margin: 0 0 20px; color: #cbd5e1;">
                                <?php if (!empty($supportEmail)): ?>
                                <?= htmlspecialchars(translateTo('email', $language)) ?>: <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #ffffff; text-decoration: underline;"><?= htmlspecialchars($supportEmail) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($supportPhone)): ?><?= !empty($supportEmail) ? ' &nbsp;|&nbsp; ' : '' ?><?= htmlspecialchars(translateTo('phone', $language)) ?>: <a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', (string) $supportPhone)) ?>" style="color: #ffffff; text-decoration: none;"><?= htmlspecialchars($supportPhone) ?></a><?php endif; ?>
                            </div>
                            <div style="border-top: 1px solid #1e293b; padding-top: 20px; font-size: 12px; color: #64748b;">
                                <p style="margin: 0;">&copy; <?= date('Y') ?> <?= htmlspecialchars((string) ($companyName ?? 'PHPTRAVELS')) ?>. All rights reserved.</p>
                                <p style="margin: 8px 0 0;"><?= htmlspecialchars(translateTo('automated_message_disclaimer', $language)) ?></p>
                            </div>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
