<?php
if (!isset($SECURE)) die('Direct access not permitted');
$cabin = (array) ($bookingData['cabin'] ?? []);
$travellers = is_array($travellersData) ? $travellersData : [];
$others = array_filter((array) ($travellers['travelers'] ?? []), 'is_array');
$escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$fmtDate = static function ($d) { return $d && strtotime($d) ? date('D, d M Y', strtotime($d)) : 'N/A'; };
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: inter, DejaVuSans, Arial, sans-serif; font-size: 9pt; color: #1a202c; line-height: 1.3; font-weight: normal; }

        /* Header */
        .header { background: #fff; padding: 12px 15px; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: middle; }
        .logo-img { height: 32px; width: auto; }
        .header-contact { text-align: center; font-size: 7pt; color: #4a5568; }
        .header-contact div { margin-bottom: 2px; }
        .header-ref { text-align: right; }
        .ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .ref-number { font-size: 13pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        /* Status Badge */
        .status-badge { background: #f0fdf4; border-left: 3px solid #10b981; padding: 8px 12px; margin-bottom: 10px; }
        .status-badge-title { font-size: 9pt; font-weight: bold; color: #065f46; margin-bottom: 1px; }
        .status-badge-text { font-size: 7pt; color: #047857; }

        /* Booking Reference */
        .booking-ref { background: #f7fafc; border: 1px solid #cbd5e0; padding: 8px; text-align: center; margin-bottom: 10px; }
        .booking-ref-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .booking-ref-number { font-size: 14pt; font-weight: bold; color: #2d3748; letter-spacing: 0.5px; }

        /* Hotel Card */
        .hotel-card { border: 1px solid #cbd5e0; margin-bottom: 10px; }
        .hotel-header { background: #2d3748; color: #fff; padding: 8px 12px; }
        .hotel-name { font-size: 11pt; font-weight: bold; margin-bottom: 2px; }
        .hotel-address { font-size: 7pt; opacity: 0.9; }

        /* Table Layouts */
        table { width: 100%; border-collapse: collapse; }
        .dates-table td { width: 50%; padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
        .dates-label { font-size: 6pt; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .dates-value { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 1px; }
        .dates-time { font-size: 7pt; color: #4a5568; }

        .stay-info { background: #f7fafc; padding: 6px 10px; border-top: 1px solid #e2e8f0; font-size: 7pt; color: #4a5568; }
        .stay-info strong { color: #2d3748; }

        .room-details { padding: 6px 10px; border-top: 1px solid #e2e8f0; }
        .room-label { font-size: 6pt; color: #718096; margin-bottom: 2px; }
        .room-type { font-size: 9pt; font-weight: normal; color: #2d3748; margin-bottom: 2px; }
        .room-board { font-size: 7pt; color: #4a5568; }

        /* Price Summary */
        .price-box { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 10px; }
        .price-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 6px; }
        .price-row { margin-bottom: 4px; }
        .price-row table { border: none; }
        .price-row td { padding: 0; border: none; font-size: 7pt; }
        .price-label { color: #4a5568; }
        .price-value { text-align: right; color: #2d3748; font-weight: normal; }
        .price-total { border-top: 1px solid #cbd5e0; padding-top: 5px; margin-top: 5px; }
        .price-total td { font-size: 9pt; font-weight: bold; }
        .price-total .price-value { font-size: 11pt; color: #2d3748; font-weight: bold; }

        /* Guest Details */
        .section-title { font-size: 9pt; font-weight: bold; color: #2d3748; margin-bottom: 5px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e0; }
        .guest-info { background: #f7fafc; padding: 6px 10px; margin-bottom: 8px; }
        .guest-row { margin-bottom: 3px; font-size: 7pt; }
        .guest-label { color: #718096; display: inline-block; width: 60px; }
        .guest-value { color: #2d3748; font-weight: normal; }

        /* Alert Boxes */
        .alert-box { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 8px; margin-bottom: 8px; }
        .alert-title { font-size: 7pt; font-weight: bold; color: #92400e; margin-bottom: 2px; }
        .alert-text { font-size: 6pt; color: #92400e; line-height: 1.3; }

        .info-box { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 6px 8px; margin-bottom: 8px; }
        .info-title { font-size: 7pt; font-weight: bold; color: #1e40af; margin-bottom: 2px; }
        .info-text { font-size: 6pt; color: #1e3a8a; line-height: 1.3; }

        /* Important Info */
        .important-info { background: #f7fafc; padding: 8px 10px; margin-top: 10px; }
        .important-info ul { margin: 4px 0 0 12px; padding: 0; }
        .important-info li { font-size: 6pt; color: #4a5568; margin-bottom: 2px; line-height: 1.3; }

        /* Footer */
        .footer { background: #f7fafc; padding: 8px 10px; margin-top: 10px; border-top: 1px solid #cbd5e0; text-align: center; }
        .footer-contact { font-size: 7pt; color: #4a5568; margin-bottom: 5px; }
        .footer-note { font-size: 6pt; color: #718096; line-height: 1.3; }
    </style>
</head><body>
<div class="header"><table><tr>
<td style="width:33%"><img src="<?= $escape($invoiceLogo ?? uploads . 'global/logo.png') ?>" class="logo-img" alt="<?= $escape($businessName) ?>"></td>
<td style="width:34%" class="header-contact"><?= $escape($businessEmail) ?><br><?= $escape($businessPhone) ?></td>
<td style="width:33%" class="header-ref"><div class="ref-label">Cruise Invoice</div><div class="ref-number"><?= $escape($invoiceId) ?></div>
<?php if (!empty($booking['pnr'])): ?><div class="ref-label">Booking Reference</div><?= $escape($booking['pnr']) ?><?php endif; ?>
</td></tr></table></div>
<div class="guest-info"><table><tr><td>Booking Date: <?= $escape($bookingDate) ?></td><td style="text-align:right">Booking: <?= $escape(ucfirst($booking['booking_status'] ?? 'pending')) ?><br>Payment: <?= $escape($statusText) ?></td></tr></table></div>
<div class="hotel-card">
<div class="hotel-header"><div class="hotel-name"><?= $escape($bookingData['cruise_name'] ?? 'Cruise') ?></div><div class="hotel-address"><?= $escape($bookingData['operator_name'] ?? '') ?> &middot; <?= $escape($bookingData['ship_name'] ?? '') ?></div></div>
<table class="dates-table">
<tr><td><div class="dates-label">Departs</div><div class="dates-value"><?= $escape($fmtDate($bookingData['start_date'] ?? '')) ?></div><div class="dates-time"><?= $escape($bookingData['embark_port'] ?? '') ?></div></td>
<td><div class="dates-label">Returns</div><div class="dates-value"><?= $escape($fmtDate($bookingData['end_date'] ?? '')) ?></div><div class="dates-time"><?= $escape(($bookingData['disembark_port'] ?? '') ?: ($bookingData['embark_port'] ?? '')) ?></div></td></tr>
<tr><td><div class="dates-label">Duration</div><div class="dates-value"><?= (int) ($bookingData['nights'] ?? 0) ?> nights</div></td>
<td><div class="dates-label">Guests</div><div class="dates-value"><?= (int) $booking['adults'] ?> adult(s)<?= (int) $booking['childs'] > 0 ? ', ' . (int) $booking['childs'] . ' child(ren)' : '' ?></div></td></tr>
</table>
<div class="room-details"><div class="room-label">CABIN</div><div class="room-type"><?= $escape($cabin['name'] ?? '') ?><?= !empty($cabin['code']) ? ' (' . $escape($cabin['code']) . ')' : '' ?></div><?php if (!empty($cabin['fare'])): ?><div class="room-board"><?= $escape($cabin['fare']) ?></div><?php endif; ?></div>
</div>
<div class="section-title">Guests</div>
<div class="guest-info">
<div class="guest-row"><span class="guest-label">Lead guest:</span> <?= $escape(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?></div>
<?php foreach ($others as $t): ?><div class="guest-row"><span class="guest-label"><?= $escape(ucfirst($t['type'] ?? 'Guest')) ?>:</span> <?= $escape(trim(($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))) ?></div><?php endforeach; ?>
<div class="guest-row"><span class="guest-label">Email:</span> <?= $escape($booking['email'] ?? '') ?></div>
<div class="guest-row"><span class="guest-label">Phone:</span> +<?= $escape(($booking['phone_country_code'] ?? '') . ' ' . ($booking['phone'] ?? '')) ?></div>
</div>
<div class="price-box"><div class="price-title">Payment Summary</div>
<div class="price-row"><table><tr><td class="price-label">Subtotal</td><td class="price-value"><?= $escape($currency) ?> <?= $escape($subtotalAmount) ?></td></tr></table></div>
<div class="price-row"><table><tr><td class="price-label">Tax</td><td class="price-value"><?= $escape($currency) ?> <?= number_format($pdfTaxAmount, 2) ?></td></tr></table></div>
<div class="price-total"><table><tr><td>Total Amount</td><td class="price-value"><?= $escape($currency) ?> <?= $escape($totalAmount) ?></td></tr></table></div>
</div>
<div class="important-info"><div class="price-title">Important information</div><ul>
<li>Your cabin is confirmed with the cruise line after payment; final cruise documents are sent by email.</li>
<li>Passports and any visas must be valid for every country on the itinerary.</li>
<li>Port taxes, gratuities and onboard charges may apply and are set by the cruise line.</li>
</ul></div>
<div class="footer"><div class="footer-contact">Questions? Contact us at <?= $escape($businessEmail) ?> | <?= $escape($businessPhone) ?></div><div class="footer-note">&copy; <?= date('Y') ?> <?= $escape($businessName) ?>. All rights reserved.<br>Please keep this invoice for your records.</div></div>
</body></html>
