<?php
// app/views/tailwind.php
@$SECURE or die('Access Denied!');

// Load active theme
$activeThemeFile = __DIR__ . '/../../app/themes/active.json';
$activeThemeData = json_decode(file_get_contents($activeThemeFile), true);
$activeThemeName = $activeThemeData['active_theme'] ?? 'default';

// Load theme configuration
$themeFile = __DIR__ . '/../../app/themes/' . $activeThemeName . '.json';
if (!file_exists($themeFile)) {
    $themeFile = __DIR__ . '/../../app/themes/default.json';
}
$theme = json_decode(file_get_contents($themeFile), true);
?>

<!-- SITE FONT IS SERVED LOCALLY (IBM Plex Sans Arabic @font-face IN assets/css/app.css,
     FILES IN assets/fonts/) — NO GOOGLE FONTS CDN FOR THE BODY FONT -->
<script>
    window.__APP_TAILWIND_CONFIG__ = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['IBM Plex Sans Arabic', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                },
                colors: {
                    // Foreground colors
                    foreground: '#111827',

                    // Primary colors
                    primary: {
                        DEFAULT: '#3b82f6',
                        foreground: '#ffffff',
                        50: '#eff6ff',
                        100: '#dbeafe',
                        200: '#bfdbfe',
                        300: '#93c5fd',
                        400: '#60a5fa',
                        500: '#3b82f6',
                        600: '#2563eb',
                        700: '#1d4ed8',
                        800: '#1e40af',
                        900: '#1e3a8a',
                        950: '#172554'
                    },

                    // Secondary colors
                    secondary: {
                        DEFAULT: '#6b7280',
                        foreground: '#ffffff',
                    },

                    // Background colors
                    background: '#ffffff',

                    // Border colors
                    border: '#e5e7eb',

                    // Muted colors
                    muted: {
                        DEFAULT: '#f3f4f6',
                        foreground: '#6b7280',
                    },

                    // Accent colors
                    accent: {
                        DEFAULT: '#f3f4f6',
                        foreground: '#111827',
                    },

                    // Card colors
                    card: {
                        DEFAULT: '#ffffff',
                        foreground: '#111827'
                    },

                    // Custom brand colors
                    brand: {
                        light: '#ebf5ff',
                        DEFAULT: '#3b82f6',
                        dark: '#1e40af'
                    }
                },

                // Text colors shorthand
                textColor: {
                    foreground: '#111827',
                },

                // Background colors shorthand
                backgroundColor: {
                    background: '#ffffff',
                    secondary: '#6b7280',
                }
            }
        }
    };
</script>

<script>
// Global checkbox functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle clicks on custom checkbox elements
    document.addEventListener('click', function(e) {
        if (e.target.closest('.checkbox-custom')) {
            const customCheckbox = e.target.closest('.checkbox-custom');
            const container = customCheckbox.closest('.checkbox-container');
            const input = container.querySelector('.checkbox-input');

            if (input && !input.disabled) {
                input.checked = !input.checked;
                // Trigger change event for proper AlpineJS reactivity
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
});
</script>

<style>
:root {
    /* Typography */
    --font-family: '<?= $theme['typography']['font_family'] ?? 'IBM Plex Sans Arabic' ?>', 'Inter', ui-sans-serif, system-ui, sans-serif;
    --font-size-base: <?= $theme['typography']['font_size_base'] ?? '16' ?>px;
    --line-height: <?= $theme['typography']['line_height'] ?? '1.5' ?>;

    /* Header Variables */
    --header-height: <?= $theme['header']['height'] ?? '64' ?>px;
    --header-font-size: <?= $theme['header']['font_size'] ?? '14' ?>px;
    --header-background: <?= $theme['header']['background'] ?? '#ffffff' ?>;
    --header-text-color: <?= $theme['header']['text_color'] ?? '#1f2937' ?>;
    --header-link-color: <?= $theme['header']['link_color'] ?? '#3b82f6' ?>;
    --header-link-hover-color: <?= $theme['header']['link_hover_color'] ?? '#2563eb' ?>;
    --header-border-color: <?= $theme['header']['border_color'] ?? '#e5e7eb' ?>;
    --header-border-width: <?= $theme['header']['border_width'] ?? '1' ?>px;
    --header-padding-x: <?= $theme['header']['padding_x'] ?? '16' ?>px;
    --header-shadow: <?= $theme['header']['shadow'] ?? '0 1px 3px 0 rgb(0 0 0 / 0.1)' ?>;

    /* Footer Variables */
    --footer-font-size: <?= $theme['footer']['font_size'] ?? '14' ?>px;
    --footer-background: <?= $theme['footer']['background'] ?? '#1f2937' ?>;
    --footer-text-color: <?= $theme['footer']['text_color'] ?? '#9ca3af' ?>;
    --footer-heading-color: <?= $theme['footer']['heading_color'] ?? '#ffffff' ?>;
    --footer-link-color: <?= $theme['footer']['link_color'] ?? '#60a5fa' ?>;
    --footer-link-hover-color: <?= $theme['footer']['link_hover_color'] ?? '#93c5fd' ?>;
    --footer-border-color: <?= $theme['footer']['border_color'] ?? '#374151' ?>;
    --footer-border-width: <?= $theme['footer']['border_width'] ?? '1' ?>px;
    --footer-padding-y: <?= $theme['footer']['padding_y'] ?? '48' ?>px;
    --footer-padding-x: <?= $theme['footer']['padding_x'] ?? '16' ?>px;

    /* Button Variables */
    --btn-height: <?= $theme['button']['height'] ?? '40' ?>px;
    --btn-padding-x: <?= $theme['button']['padding_x'] ?? '24' ?>px;
    --btn-padding-y: <?= $theme['button']['padding_y'] ?? '10' ?>px;
    --btn-font-size: <?= $theme['button']['font_size'] ?? '14' ?>px;
    --btn-font-weight: <?= $theme['button']['font_weight'] ?? '500' ?>;
    --btn-border-radius: <?= $theme['button']['border_radius'] ?? '8' ?>px;
    --btn-border-width: <?= $theme['button']['border_width'] ?? '0' ?>px;
    --btn-background: <?= $theme['button']['background'] ?? '#3b82f6' ?>;
    --btn-background-hover: <?= $theme['button']['background_hover'] ?? '#2563eb' ?>;
    --btn-text-color: <?= $theme['button']['text_color'] ?? '#ffffff' ?>;
    --btn-border-color: <?= $theme['button']['border_color'] ?? '#3b82f6' ?>;

    /* Input Variables */
    --input-height: <?= $theme['input']['height'] ?? '40' ?>px;
    --input-padding-x: <?= $theme['input']['padding_x'] ?? '24' ?>px;
    --input-padding-y: <?= $theme['input']['padding_y'] ?? '10' ?>px;
    --input-font-size: <?= $theme['input']['font_size'] ?? '14' ?>px;
    --input-font-weight: <?= $theme['input']['font_weight'] ?? '400' ?>;
    --input-border-radius: <?= $theme['input']['border_radius'] ?? '8' ?>px;
    --input-border-width: <?= $theme['input']['border_width'] ?? '1' ?>px;
    --input-background: <?= $theme['input']['background'] ?? '#ffffff' ?>;
    --input-text-color: <?= $theme['input']['text_color'] ?? '#1f2937' ?>;
    --input-border-color: <?= $theme['input']['border_color'] ?? '#e5e7eb' ?>;
    --input-border-focus-color: <?= $theme['input']['border_focus_color'] ?? '#3b82f6' ?>;
    --input-placeholder-color: <?= $theme['input']['placeholder_color'] ?? '#9ca3af' ?>;

    /* Textarea Variables */
    --textarea-min-height: <?= $theme['textarea']['min_height'] ?? '80' ?>px;
    --textarea-padding-x: <?= $theme['textarea']['padding_x'] ?? '24' ?>px;
    --textarea-padding-y: <?= $theme['textarea']['padding_y'] ?? '12' ?>px;
    --textarea-font-size: <?= $theme['textarea']['font_size'] ?? '14' ?>px;
    --textarea-font-weight: <?= $theme['textarea']['font_weight'] ?? '400' ?>;
    --textarea-border-radius: <?= $theme['textarea']['border_radius'] ?? '8' ?>px;
    --textarea-border-width: <?= $theme['textarea']['border_width'] ?? '1' ?>px;
    --textarea-background: <?= $theme['textarea']['background'] ?? '#ffffff' ?>;
    --textarea-text-color: <?= $theme['textarea']['text_color'] ?? '#1f2937' ?>;
    --textarea-border-color: <?= $theme['textarea']['border_color'] ?? '#e5e7eb' ?>;
    --textarea-border-focus-color: <?= $theme['textarea']['border_focus_color'] ?? '#3b82f6' ?>;
    --textarea-placeholder-color: <?= $theme['textarea']['placeholder_color'] ?? '#9ca3af' ?>;

    /* Select Variables */
    --select-height: <?= $theme['select']['height'] ?? '40' ?>px;
    --select-padding-x: <?= $theme['select']['padding_x'] ?? '24' ?>px;
    --select-padding-y: <?= $theme['select']['padding_y'] ?? '10' ?>px;
    --select-font-size: <?= $theme['select']['font_size'] ?? '14' ?>px;
    --select-font-weight: <?= $theme['select']['font_weight'] ?? '400' ?>;
    --select-border-radius: <?= $theme['select']['border_radius'] ?? '8' ?>px;
    --select-border-width: <?= $theme['select']['border_width'] ?? '1' ?>px;
    --select-background: <?= $theme['select']['background'] ?? '#ffffff' ?>;
    --select-text-color: <?= $theme['select']['text_color'] ?? '#1f2937' ?>;
    --select-border-color: <?= $theme['select']['border_color'] ?? '#e5e7eb' ?>;
    --select-border-focus-color: <?= $theme['select']['border_focus_color'] ?? '#3b82f6' ?>;

    /* Card Variables */
    --card-padding: <?= $theme['card']['padding'] ?? '24' ?>px;
    --card-border-radius: <?= $theme['card']['border_radius'] ?? '8' ?>px;
    --card-border-width: <?= $theme['card']['border_width'] ?? '1' ?>px;
    --card-background: <?= $theme['card']['background'] ?? '#ffffff' ?>;
    --card-border-color: <?= $theme['card']['border_color'] ?? '#e5e7eb' ?>;
    --card-shadow: <?= $theme['card']['shadow'] ?? '0 1px 3px 0 rgb(0 0 0 / 0.1)' ?>;

    /* Checkbox Variables */
    --checkbox-size: <?= $theme['checkbox']['size'] ?? '18' ?>px;
    --checkbox-border-radius: <?= $theme['checkbox']['border_radius'] ?? '4' ?>px;
    --checkbox-border-width: <?= $theme['checkbox']['border_width'] ?? '2' ?>px;
    --checkbox-background: <?= $theme['checkbox']['background'] ?? '#ffffff' ?>;
    --checkbox-background-checked: <?= $theme['checkbox']['background_checked'] ?? '#3b82f6' ?>;
    --checkbox-border-color: <?= $theme['checkbox']['border_color'] ?? '#d1d5db' ?>;
    --checkbox-border-checked-color: <?= $theme['checkbox']['border_checked_color'] ?? '#3b82f6' ?>;
    --checkbox-checkmark-color: <?= $theme['checkbox']['checkmark_color'] ?? '#ffffff' ?>;

    /* Radio Variables */
    --radio-size: <?= $theme['radio']['size'] ?? '16' ?>px;
    --radio-border-width: <?= $theme['radio']['border_width'] ?? '1' ?>px;
    --radio-background: <?= $theme['radio']['background'] ?? '#ffffff' ?>;
    --radio-border-color: <?= $theme['radio']['border_color'] ?? '#e5e7eb' ?>;
    --radio-border-checked-color: <?= $theme['radio']['border_checked_color'] ?? '#3b82f6' ?>;
    --radio-dot-color: <?= $theme['radio']['dot_color'] ?? '#3b82f6' ?>;

    /* Color Variables */
    --color-primary: <?= $theme['colors']['primary'] ?? '#3b82f6' ?>;
    --color-secondary: <?= $theme['colors']['secondary'] ?? '#6b7280' ?>;
    --color-success: <?= $theme['colors']['success'] ?? '#10b981' ?>;
    --color-danger: <?= $theme['colors']['danger'] ?? '#ef4444' ?>;
    --color-warning: <?= $theme['colors']['warning'] ?? '#f59e0b' ?>;
    --color-info: <?= $theme['colors']['info'] ?? '#06b6d4' ?>;
    --color-text: <?= $theme['colors']['text'] ?? '#1f2937' ?>;
    --color-text-muted: <?= $theme['colors']['text_muted'] ?? '#6b7280' ?>;
    --color-background: <?= $theme['colors']['background'] ?? '#ffffff' ?>;
    --color-background-muted: <?= $theme['colors']['background_muted'] ?? '#f9fafb' ?>;
    --color-border: <?= $theme['colors']['border'] ?? '#e5e7eb' ?>;

    /* Legacy variables for backward compatibility */
    --radius: 0.5rem;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --transition-default: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    --color-brand: var(--color-primary);
}

/* Apply typography globally */
body {
    font-family: var(--font-family) !important;
    font-size: var(--font-size-base) !important;
    line-height: var(--line-height) !important;
    color: var(--color-text) !important;
}

/* Prevent collapsing/distortion of absolutely positioned SVGs in search inputs (Safari flexbox bug on iPhones) */
svg.absolute[class*="left-3"],
svg.absolute.left-3,
.absolute.left-3 svg {
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    min-height: 20px !important;
    flex-shrink: 0 !important;
}
.field-box svg.field-box-icon,
.field-box-segment svg.field-box-icon,
svg.field-box-icon {
    width: 16px !important;
    height: 16px !important;
    min-width: 16px !important;
    min-height: 16px !important;
    flex-shrink: 0 !important;
}

/* Ensure field boxes and segments prevent flex shrinking of HG/Material symbols and SVG icons */
.field-box svg,
.field-box-segment svg,
.field-box i,
.field-box-segment i,
.field-box span.material-symbols-outlined,
.field-box-segment span.material-symbols-outlined,
.field-box-icon,
svg.field-box-icon {
    flex-shrink: 0 !important;
}

/* ============================================================
   MOTION + FIELD COLOURS (one place to tune them)
   Springs are written as linear() curves: stiffness / damping.
   ============================================================ */
:root {
    --field-border: #a3a3a3;
    --field-bg: #fafafa;
    --field-label: #a3a3a3;
    --field-text: #262626;
    --ease-soft: cubic-bezier(.25, .46, .45, .94);
    /* 400 / 25: floating label */
    --spring-label: linear(0, 0.0577, 0.1953, 0.3684, 0.5455, 0.706, 0.8389, 0.9401, 1.0104, 1.0537, 1.0753, 1.0808, 1.0756, 1.0643, 1.0502, 1.0359, 1.0231, 1.0125, 1.0044, 0.9989, 0.9955, 0.9939, 0.9935, 0.9939, 0.9949, 0.996, 0.9972, 0.9982, 1);
    /* 300 / 25, mass .8: popup */
    --spring-pop: linear(0, 0.0401, 0.1356, 0.2577, 0.387, 0.5111, 0.6229, 0.7188, 0.798, 0.8612, 0.9099, 0.9462, 0.9723, 0.9902, 1.0019, 1.0088, 1.0124, 1.0136, 1.0134, 1.0123, 1.0106, 1.0089, 1.0072, 1.0056, 1.0042, 1.003, 1.0021, 1.0014, 1);
    /* 400 / 20: rows inside a popup */
    --spring-row: linear(0, 0.0887, 0.2951, 0.542, 0.7737, 0.9578, 1.0813, 1.146, 1.163, 1.1468, 1.1124, 1.0721, 1.0347, 1.0053, 0.9858, 0.9758, 0.9735, 0.9764, 0.9821, 0.9887, 0.9947, 0.9994, 1.0025, 1.004, 1.0043, 1.0038, 1.0029, 1.0018, 1);
    /* 420 / 38: full-screen sheet on phones */
    --spring-sheet: linear(0, 0.0259, 0.0891, 0.173, 0.266, 0.3603, 0.4509, 0.5348, 0.6104, 0.6772, 0.735, 0.7845, 0.8262, 0.8611, 0.8899, 0.9134, 0.9326, 0.9479, 0.9602, 0.9699, 0.9775, 0.9834, 0.9879, 0.9914, 0.994, 0.996, 0.9974, 0.9984, 1);
}

/* ============================================================
   SEARCH FIELD SKIN
   Search fields written with utilities (h-[67px] pl-11 ...) get the same
   look as the .field-box component: a small icon and the label on the first
   line, the value on the second. While a field is empty its label sits in
   the middle; it moves up when a value comes in. Selectors are heavier than
   a utility class, so they win without !important (except where the markup
   has inline styles).
   ============================================================ */
[class~="h-[67px]"][class~="pl-11"] {
    padding-left: 20px;
    padding-right: 14px;
    border: 1px solid var(--field-border);
    border-radius: 8px;
    background-color: var(--field-bg);
    transition: border-color .2s ease, box-shadow .2s ease, transform .15s ease;
}
[class~="h-[67px]"][class~="pl-11"]:hover {
    border-color: var(--color-primary);
    background-color: var(--field-bg);
}
[class~="h-[67px]"][class~="pl-11"]:active {
    transform: scale(.98);
}
/* open: the field keeps its shape, the popup floats under it */
[class~="h-[67px]"][class~="pl-11"][class*="border-[#1570ef]"] {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 10%, transparent);
}
[class~="h-[67px]"][class~="pl-11"] > .absolute.left-3 {
    left: 20px;
    top: 12.5px;
    transform: none;
    width: 16px !important;
    height: 16px !important;
    min-width: 16px !important;
    min-height: 16px !important;
    font-size: 16px !important;
    line-height: 16px;
    color: var(--field-label);
    transition: transform .52s var(--spring-label);
}
[class~="h-[67px]"][class~="pl-11"] > .flex-1 {
    padding-bottom: 2px;
}
[class~="h-[67px]"][class~="pl-11"] > .flex-1 > :first-child {
    padding-left: 24px;
    margin-bottom: 0;
    font-size: 14px;
    font-weight: 400;
    line-height: 20px;
    color: var(--field-label);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: transform .52s var(--spring-label);
}
[class~="h-[67px]"][class~="pl-11"] > .flex-1 > :not(:first-child) {
    margin-top: -2px;
    font-size: 16px;
    font-weight: 500;
    line-height: 24px;
}
[class~="h-[67px]"][class~="pl-11"] > .flex-1 > [class*="text-[#344054]"] {
    color: var(--field-text);
    animation: field-value-in .3s var(--ease-soft) both;
}
@keyframes field-value-in {
    from { opacity: 0; transform: translateY(8px); }
}

/* empty field: only the label, in the middle */
@supports selector(:has(*)) {
    [class~="h-[67px]"][class~="pl-11"] > .flex-1 > [class*="text-[#98a2b3]"][x-show] {
        opacity: 0;
    }
    [class~="h-[67px]"][class~="pl-11"]:has(> .flex-1 > [class*="text-[#98a2b3]"][x-show]:not([style*="display: none"])) > .flex-1 > :first-child,
    [class~="h-[67px]"][class~="pl-11"]:has(> .flex-1 > [class*="text-[#98a2b3]"][x-show]:not([style*="display: none"])) > .absolute.left-3 {
        transform: translateY(12px);
    }
}

/* right-to-left: the icon and the label start from the right */
[dir="rtl"] [class~="h-[67px]"][class~="pl-11"] {
    padding-left: 14px;
    padding-right: 20px;
}
[dir="rtl"] [class~="h-[67px]"][class~="pl-11"] > .absolute.left-3 {
    left: auto;
    right: 20px;
}
[dir="rtl"] [class~="h-[67px]"][class~="pl-11"] > .flex-1 > :first-child {
    padding-left: 0;
    padding-right: 24px;
}
[dir="rtl"] [class~="h-[67px]"][class~="pl-11"] > .ml-2 {
    margin-left: 0;
    margin-right: 8px;
}
[dir="rtl"] .field-box-icon {
    left: auto !important;
    right: 20px;
}
[dir="rtl"] .field-box-label {
    padding-left: 0;
    padding-right: 24px;
}
[dir="rtl"] .field-box-chevron {
    right: auto;
    left: 16px;
}
/* no arrow at the end of a field: the whole field opens the popup */
[class~="h-[67px]"][class~="pl-11"] > .material-symbols-outlined.ml-2 {
    display: none;
}

/* small dropdown next to the trip type (cabin class) */
button[class~="h-[42px]"][class~="w-full"] {
    height: 46px;
    padding: 0 14px;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    background: #fff;
    color: var(--field-text);
    transition: border-color .2s ease, box-shadow .2s ease;
}
button[class~="h-[42px]"][class~="w-full"]:hover,
button[class~="h-[42px]"][class~="w-full"][class*="border-[#1570ef]"] {
    border-color: var(--color-primary);
    background: #fff;
}

/* SWAP — the two fields curve around the round button between them.
   Markup: .swap-wrap-end on the wrapper of the first field, .swap-wrap-start
   on the wrapper of the second one, .swap-btn for the button. */
@media (min-width: 1024px) {
    .swap-wrap-end::after, .swap-wrap-start::after {
        content: '';
        position: absolute;
        top: 33.5px;
        z-index: 1;
        width: 48px;
        height: 48px;
        margin-top: -24px;
        border: 1px solid var(--field-border);
        border-radius: 50%;
        background: #fff;
        transition: border-color .2s ease;
        pointer-events: none;
    }
    /* half of each circle lies on its field; the other half is cut away */
    .swap-wrap-end::after   { right: -30px; clip-path: inset(0 30px 0 0); }
    .swap-wrap-start::after { left: -30px;  clip-path: inset(0 0 0 30px); }
    .swap-wrap-end:hover::after, .swap-wrap-start:hover::after,
    .swap-wrap-end:has(> [class*="border-[#1570ef]"])::after,
    .swap-wrap-start:has(> [class*="border-[#1570ef]"])::after {
        border-color: var(--color-primary);
    }
    .swap-wrap-end > [class~="h-[67px]"][class~="pl-11"] { padding-right: 36px; }
    .swap-wrap-start > [class~="h-[67px]"][class~="pl-11"] { padding-left: 44px; }
    .swap-wrap-start > [class~="h-[67px]"][class~="pl-11"] > .absolute.left-3 { left: 44px; }
}
.swap-btn {
    position: absolute;
    top: 33.5px;
    right: -29px;
    z-index: 10;
    display: none;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    margin-top: -23px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: #fff;
    color: var(--field-label);
    outline: none;
    transition: color .2s ease, box-shadow .2s ease;
}
@media (min-width: 1024px) {
    .swap-btn { display: flex; }
}
.swap-btn:hover { color: var(--color-primary); }
.swap-btn:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 10%, transparent); }
.swap-btn .material-symbols-outlined {
    font-size: 24px !important;
    transition: transform .52s var(--spring-label);
}
.swap-btn:hover .material-symbols-outlined { transform: rotate(180deg); }

/* right-to-left: the first field is on the right, so the sides change */
@media (min-width: 1024px) {
    [dir="rtl"] .swap-wrap-end::after   { right: auto; left: -30px; clip-path: inset(0 0 0 30px); }
    [dir="rtl"] .swap-wrap-start::after { left: auto; right: -30px; clip-path: inset(0 30px 0 0); }
    [dir="rtl"] .swap-wrap-end > [class~="h-[67px]"][class~="pl-11"] { padding-right: 20px; padding-left: 36px; }
    [dir="rtl"] .swap-wrap-start > [class~="h-[67px]"][class~="pl-11"] { padding-left: 14px; padding-right: 44px; }
    [dir="rtl"] .swap-wrap-start > [class~="h-[67px]"][class~="pl-11"] > .absolute.left-3 { left: auto; right: 44px; }
}
[dir="rtl"] .swap-btn { right: auto; left: -29px; }

/* .field-box: the same floating label. Empty and not in use = label in the middle. */
.field-box .field-box-label,
.field-box .field-box-icon {
    transition: transform .52s var(--spring-label);
}
@supports selector(:has(*)) {
    .field-box .field-box-input:placeholder-shown:not(:focus)::placeholder {
        color: transparent;
    }
    .field-box:not(.field-box-split):not(:focus-within):has(.field-box-input:placeholder-shown) .field-box-label,
    .field-box:not(.field-box-split):not(:focus-within):has(.field-box-input:placeholder-shown) > .field-box-icon,
    .field-box-segment:not(:focus-within):has(.field-box-input:placeholder-shown) .field-box-label,
    .field-box-segment:not(:focus-within):has(.field-box-input:placeholder-shown) > .field-box-icon {
        transform: translateY(12px);
    }
}

/* two dates in one field: the second half starts close to the divider, so both dates fit */
.field-box-split > .field-box-segment {
    padding-right: 4px;
}
.field-box-split > .field-box-segment.pl-9 {
    padding-left: 12px;
}
.field-box-split > .field-box-segment.pl-9 > .field-box-icon {
    left: 12px !important;
}
[dir="rtl"] .field-box-split > .field-box-segment {
    padding-right: 20px;
    padding-left: 4px;
}
[dir="rtl"] .field-box-split > .field-box-segment.pl-9 {
    padding-right: 12px;
}
[dir="rtl"] .field-box-split > .field-box-segment.pl-9 > .field-box-icon {
    left: auto !important;
    right: 12px;
}

/* ============================================================
   ICONS OF A FIELD
   One size (16px), one colour, one line weight, one place:
   whatever the icon is made of (icon font or drawing) and
   whichever markup the field uses.
   ============================================================ */
.field-box .field-box-icon,
.field-box-segment .field-box-icon,
[class~="h-[67px]"][class~="pl-11"] > .field-box-icon,
[class~="h-[67px]"][class~="pl-11"] > .absolute.left-3 {
    top: 12.5px;
    width: 16px !important;
    height: 16px !important;
    min-width: 16px !important;
    min-height: 16px !important;
    font-size: 16px !important;
    line-height: 16px !important;
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    color: var(--field-label);
    overflow: hidden;
    pointer-events: none;
}
/* drawings get the line weight of the icon font at this size */
svg.field-box-icon [stroke-width],
[class~="h-[67px]"][class~="pl-11"] > svg.absolute.left-3 [stroke-width] {
    stroke-width: 1.8;
}
[class~="h-[67px]"][class~="pl-11"] > .field-box-icon {
    left: 20px;
    transform: none;
    transition: transform .52s var(--spring-label);
}
[dir="rtl"] [class~="h-[67px]"][class~="pl-11"] > .field-box-icon {
    left: auto;
    right: 20px;
}

/* no arrows at the end of a field: the whole field opens its popup */
.field-box .field-box-chevron,
.field-box > .material-symbols-outlined.ml-2 {
    display: none;
}

/* date + time in one field: the time half is only as wide as a time, the date gets the rest */
.field-box-split > .field-box-segment[class~="w-[90px]"] {
    flex: 0 0 84px;
    width: 84px;
}

/* a field keeps its border and its corners while its popup is open */
.field-box.is-open[class~="border-b-0"] {
    border-bottom-width: 1px;
    border-radius: 8px;
}
.field-box[class*="border-[#d8dce3]"] {
    border-color: var(--field-border);
}
.field-box[class*="border-[#1570ef]"] {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 10%, transparent);
    --tw-ring-shadow: 0 0 #0000;
}

/* a field whose value is a text block (not an input): same floating label */
@supports selector(:has(*)) {
    .field-box-content > .field-box-value[x-show][class*="98a2b3"] {
        opacity: 0;
    }
    [class~="h-[67px]"][class~="pl-11"]:has(> .flex-1 > [class*="text-[#98a2b3]"][x-show]:not([style*="display: none"])) > .field-box-icon,
    .field-box:not(.field-box-split):has(> .field-box-content > .field-box-value[x-show][class*="98a2b3"]:not([style*="display: none"])) > .field-box-icon,
    .field-box:not(.field-box-split):has(> .field-box-content > .field-box-value[x-show][class*="98a2b3"]:not([style*="display: none"])) .field-box-label {
        transform: translateY(12px);
    }
}

/* swap between two .field-box fields (rail) */
@media (min-width: 1024px) {
    .swap-wrap-end > .field-box { padding-right: 36px; }
    .swap-wrap-start > .field-box { padding-left: 44px; }
    .swap-wrap-start > .field-box > .field-box-icon { left: 44px; }
    [dir="rtl"] .swap-wrap-end > .field-box { padding-right: 20px; padding-left: 36px; }
    [dir="rtl"] .swap-wrap-start > .field-box { padding-left: 20px; padding-right: 44px; }
    [dir="rtl"] .swap-wrap-start > .field-box > .field-box-icon { left: auto !important; right: 44px; }
}

/* SEARCH BUTTON — label + icon, as tall as the fields */
/* the text is as large as the value of a field */
.btn.search-btn,
.search-btn .search-btn-label {
    font-size: 16px !important;
    font-weight: 500;
    line-height: 24px;
}
.search-btn {
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.2);
}
.search-btn svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

/* ============================================================
   POPUP
   Every dropdown of a search field: a floating card that flips
   in from the field (spring), its rows follow one after another,
   and it fades out a little smaller. On phones the full-screen
   sheets slide in from the side.
   Markup: x-transition:enter="pop-enter" x-transition:leave="pop-leave"
   ============================================================ */
[x-transition\:enter="pop-enter"] {
    transform-origin: top center;
    animation: pop-in .45s var(--spring-pop) both;
}
[x-transition\:enter="pop-enter"].pop-leave {
    animation: pop-out .15s ease-out both;
}
@keyframes pop-in {
    from { opacity: 0; transform: translateY(-20px) scale(.8) rotateX(-90deg); }
    to   { opacity: 1; transform: none; }
}
@keyframes pop-out {
    to { opacity: 0; transform: scale(.97); }
}
/* no room under the field: the popup opens above it (data-side is set by app.js) */
@keyframes pop-in-up {
    from { opacity: 0; transform: translateY(20px) scale(.8) rotateX(90deg); }
    to   { opacity: 1; transform: none; }
}
[x-transition\:enter="pop-enter"][data-side="top"] {
    transform-origin: bottom center;
    animation-name: pop-in-up;
}
[x-transition\:enter="pop-enter"][data-side="top"].absolute {
    top: auto !important;
    bottom: 100%;
}
[x-transition\:enter="pop-enter"][data-side="top"].pop-leave {
    animation-name: pop-out;
}
/* a popup with counters (passengers, rooms) is never narrower than its content needs */
[x-transition\:enter="pop-enter"].absolute.p-3 {
    min-width: min(340px, calc(100vw - 32px));
}
@media (min-width: 1024px) {
    [x-transition\:enter="pop-enter"] {
        border: 0 !important;
        border-radius: 12px !important;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1), 0 4px 6px -4px rgb(0 0 0 / .1), 0 0 0 1px rgb(0 0 0 / .04) !important;
    }
    [x-transition\:enter="pop-enter"] {
        margin-top: 8px;
    }
    [x-transition\:enter="pop-enter"][data-side="top"] {
        margin-top: 0;
        margin-bottom: 8px;
    }
}
@media (max-width: 1023px) {
    [x-transition\:enter="pop-enter"].fixed {
        animation: sheet-in .34s var(--spring-sheet) both;
    }
    [dir="rtl"] [x-transition\:enter="pop-enter"].fixed {
        animation-name: sheet-in-rtl;
    }
    [x-transition\:enter="pop-enter"].fixed.pop-leave {
        animation: pop-out .15s ease-out both;
    }
}
@keyframes sheet-in { from { transform: translateX(100%); } to { transform: none; } }
@keyframes sheet-in-rtl { from { transform: translateX(-100%); } to { transform: none; } }

/* search box inside a popup */
[x-transition\:enter="pop-enter"] [class*="focus-within:border-[#1570ef]"] {
    border-radius: 10px;
    border-color: #e5e5e5;
    background: #fafafa;
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
}
[x-transition\:enter="pop-enter"] [class*="focus-within:border-[#1570ef]"]:focus-within {
    border-color: var(--color-primary);
    background: #fff;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 20%, transparent);
}

/* rows come in one after another */
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"] {
    animation: pop-row .64s var(--spring-row) both;
}
@keyframes pop-row {
    from { opacity: 0; transform: perspective(1000px) translateY(-20px) scale(.9) rotateX(-45deg); }
    to   { opacity: 1; transform: perspective(1000px) translateY(0) scale(1) rotateX(0deg); }
}
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(2)  { animation-delay: 40ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(3)  { animation-delay: 80ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(4)  { animation-delay: 120ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(5)  { animation-delay: 160ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(6)  { animation-delay: 200ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(7)  { animation-delay: 240ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(8)  { animation-delay: 280ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(9)  { animation-delay: 320ms; }
[x-transition\:enter="pop-enter"] [class~="cursor-pointer"][class*="hover:bg-"]:nth-child(n+10) { animation-delay: 360ms; }

/* calendar and clock use the same entrance (not as a phone sheet, which is placed with a transform) */
@media (min-width: 769px) {
    .datepicker:not(.hidden), .timepicker:not(.hidden) {
        transform-origin: top center;
        border: 0;
        border-radius: 12px;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1), 0 4px 6px -4px rgb(0 0 0 / .1), 0 0 0 1px rgb(0 0 0 / .04);
        animation: pop-in .45s var(--spring-pop) both;
    }
    .datepicker[data-side="top"]:not(.hidden), .timepicker[data-side="top"]:not(.hidden) {
        transform-origin: bottom center;
        animation-name: pop-in-up;
    }
}

@media (prefers-reduced-motion: reduce) {
    [x-transition\:enter="pop-enter"],
    [x-transition\:enter="pop-enter"] [class~="cursor-pointer"],
    .datepicker:not(.hidden), .timepicker:not(.hidden),
    [class~="h-[67px]"][class~="pl-11"] > .flex-1 > * {
        animation-duration: .01ms !important;
        animation-delay: 0s !important;
    }
}
</style>

<style type="text/tailwindcss">
@layer components {
    /* Reusable Field Box (label-inside style)
       Usage:
         <div class="field-box">
           <span class="field-box-icon material-symbols-outlined">flight_takeoff</span>
           <div class="field-box-content">
             <label class="field-box-label">Label</label>
             <input class="field-box-input" />
           </div>
         </div>

       Split variant (two halves with a divider, e.g. depart/return):
         <div class="field-box field-box-split">
           <div class="field-box-segment"> ... </div>
           <div class="field-box-divider">→</div>
           <div class="field-box-segment"> ... </div>
         </div>
    */
    .field-box {
        @apply relative flex flex-nowrap items-center h-[67px] w-full rounded-lg border border-[#a3a3a3] bg-[#fafafa] pl-5 pr-5 cursor-text transition-all duration-200 ease-out;
    }

    .field-box:hover {
        border-color: var(--color-primary);
    }

    .field-box:focus-within,
    .field-box.is-open {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 10%, transparent);
    }

    .field-box-split {
        @apply items-stretch p-0 pl-0 pr-0 overflow-hidden cursor-default;
    }

    .field-box-segment {
        @apply relative flex-1 min-w-0 flex flex-nowrap items-center pl-5 pr-2 cursor-pointer transition-colors duration-200 ease-out hover:bg-[#f5f5f5];
    }

    .field-box-divider {
        @apply flex items-center px-1 text-[#98a2b3] flex-shrink-0;
    }

    .field-box-icon {
        @apply absolute left-5 top-[12.5px] translate-y-0 text-[16px] leading-[16px] text-[#a3a3a3] pointer-events-none w-4 h-4;
    }



    .field-box-content {
        @apply flex flex-col justify-center w-full min-w-0 pb-0.5 text-start;
    }

    .field-box-label {
        @apply block pl-6 text-[14px] font-normal leading-[20px] text-[#a3a3a3] pointer-events-none text-start truncate;
    }

    .field-box-input {
        @apply w-full border-0 outline-none focus:outline-none focus:ring-0 p-0 -mt-0.5 bg-transparent text-[16px] font-medium leading-[24px] text-[#262626] placeholder:text-[#a3a3a3] placeholder:font-normal truncate text-start;
    }

    .field-box-value {
        @apply -mt-0.5 text-[16px] font-medium leading-[24px] text-[#262626] truncate text-start;
    }

    .field-box-chevron {
        @apply absolute right-4 top-1/2 -translate-y-1/2 transition-transform text-base text-[#737373] pointer-events-none;
    }

    .field-box-action {
        @apply absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded hover:bg-[#e4e7ec] flex items-center justify-center transition-colors;
    }

    /* Segmented control: one choice out of a few, e.g. the flight trip type.
         <div class="segmented">
           <button class="segmented-item is-active">One Way</button>
           <button class="segmented-item">Round Trip</button>
           <button class="segmented-item is-soon" disabled>Open Return <span class="soon-badge">Soon</span></button>
         </div>
    */
    .segmented {
        @apply inline-flex max-w-full items-center gap-0.5 rounded-xl bg-[#f5f5f5] p-1 overflow-x-auto;
        scrollbar-width: none;
    }

    .segmented::-webkit-scrollbar {
        display: none;
    }

    .segmented-item {
        @apply relative inline-flex flex-shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-[9px] px-[10px] py-[9px] text-xs font-medium text-[#737373] outline-none transition-colors md:px-4 md:text-sm;
    }

    .segmented-item:hover {
        @apply bg-[#e5e5e5]/60;
    }

    .segmented-item.is-active {
        @apply bg-white;
        color: var(--color-primary);
        box-shadow: 0 1px 2px rgb(15 15 26 / 0.10), 0 1px 3px rgb(15 15 26 / 0.06);
    }

    .segmented-item.is-soon {
        @apply cursor-default text-[#a3a3a3] hover:bg-transparent;
    }

    .soon-badge {
        @apply inline-flex items-center rounded-full bg-[#262626] px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white;
    }

    /* Layout Components */
    .page-container {
        @apply min-h-screen bg-white font-sans antialiased;
    }

    .container {
        @apply mx-auto px-6 max-w-7xl;
    }

    .page-header {
        @apply space-y-2 mb-8;
    }

    .page-header h1 {
        @apply text-3xl font-bold tracking-tight text-gray-900;
    }

    .page-header p {
        @apply text-gray-600;
    }

    /* Component Section */
    .section {
        @apply bg-slate-50 rounded-3xl p-4 sm:p-8 border border-gray-200 mb-8;
    }

    .section-header {
        @apply mb-6;
    }

    .section-header h2 {
        @apply text-xl font-semibold tracking-tight text-gray-900;
    }

    .section-header p {
        @apply text-sm text-gray-600 mt-1;
    }

    /* Component Demo */
    .component-demo {
        @apply space-y-3;
    }

    .component-title {
        @apply text-sm font-medium text-gray-900;
    }

    .component-preview {
        @apply space-y-3;
    }

    .section-tabs {
        @apply mt-6 border-t border-gray-200 pt-4;
    }

    .section-tabs .tab-list {
        @apply flex space-x-1;
    }

    .section-tabs .tab {
        @apply inline-flex items-center justify-center rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-white transition-all focus-visible:outline-none hover:bg-gray-100 hover:text-gray-900 cursor-pointer text-gray-600;
    }

    .section-tabs .tab.active {
        @apply bg-white text-gray-900 shadow-sm;
    }

    .section-tabs .tab-content {
        @apply mt-2 hidden;
    }

    .section-tabs .tab-content.active {
        @apply block;
    }

    .code-block {
        @apply bg-gray-100 p-4 rounded-md text-sm overflow-x-auto text-gray-900 font-mono;
    }

    /* Form Components */
    .form-grid {
        @apply grid grid-cols-1 md:grid-cols-2 gap-6;
    }

    .form-control {
        @apply space-y-2;
    }

    .form-control label {
        @apply flex items-center space-x-1 gap-2 text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 text-gray-900;
    }

    .form-control i {
        @apply text-sm text-slate-500;
    }

    .textarea {
        @apply flex w-full resize-none transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none;
        min-height: var(--textarea-min-height);
        padding: var(--textarea-padding-y) var(--textarea-padding-x);
        font-size: var(--textarea-font-size);
        font-weight: var(--textarea-font-weight);
        border-radius: var(--textarea-border-radius);
        border-width: var(--textarea-border-width);
        background-color: var(--textarea-background);
        color: var(--textarea-text-color);
        border-color: var(--textarea-border-color);
    }

    .textarea:hover:not(:disabled) {
        border-color: var(--textarea-border-focus-color);
        opacity: 0.8;
    }

    .textarea:focus {
        border-color: var(--textarea-border-focus-color);
    }

    .textarea::placeholder {
        color: var(--textarea-placeholder-color);
    }

    .select {
        @apply w-full transition-colors duration-200 appearance-none focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 bg-no-repeat;
        height: var(--select-height);
        padding: var(--select-padding-y) var(--select-padding-x);
        padding-right: 2.5rem;
        font-size: var(--select-font-size);
        font-weight: var(--select-font-weight);
        border-radius: var(--select-border-radius);
        border-width: var(--select-border-width);
        background-color: var(--select-background);
        color: var(--select-text-color);
        border-color: var(--select-border-color);
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
        background-position: right 0.75rem center;
        background-size: 1.25em 1.25em;
    }

    .select:hover:not(:disabled) {
        border-color: var(--select-border-focus-color);
        opacity: 0.8;
    }

    .select:focus {
        border-color: var(--select-border-focus-color);
    }

    /* Input with Icons */
    .input-group {
        @apply relative flex items-center;
    }

    .input-with-icon {
        @apply flex w-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none;
        height: var(--input-height);
        padding: var(--input-padding-y) var(--input-padding-x);
        padding-left: 2.5rem;
        font-size: var(--input-font-size);
        font-weight: var(--input-font-weight);
        border-radius: var(--input-border-radius);
        border-width: var(--input-border-width);
        background-color: var(--input-background);
        color: var(--input-text-color);
        border-color: var(--input-border-color);
    }

    .input-with-icon:hover:not(:disabled) {
        border-color: var(--input-border-focus-color);
        opacity: 0.8;
    }

    .input-with-icon:focus {
        border-color: var(--input-border-focus-color);
    }

    .input-with-icon::placeholder {
        color: var(--input-placeholder-color);
    }

    .input {
        @apply flex w-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none;
        height: var(--input-height);
        padding: var(--input-padding-y) var(--input-padding-x);
        font-size: var(--input-font-size);
        font-weight: var(--input-font-weight);
        border-radius: var(--input-border-radius);
        border-width: var(--input-border-width);
        background-color: var(--input-background);
        color: var(--input-text-color);
        border-color: var(--input-border-color);
    }

    .input:hover:not(:disabled) {
        border-color: var(--input-border-focus-color);
        opacity: 0.8;
    }

    .input:focus {
        border-color: var(--input-border-focus-color);
    }

    .input::placeholder {
        color: var(--input-placeholder-color);
    }

    .input-with-icon-right {
        @apply flex h-10 w-full rounded-lg border border-gray-200 bg-white pl-3 pr-10 py-2 text-sm text-gray-700 placeholder:text-gray-400 focus:outline-none focus:border-blue-600 hover:border-blue-600/50 disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-gray-50 transition-colors duration-200;
    }

    .input-with-icons {
        @apply flex h-10 w-full rounded-lg border border-gray-200 bg-white pl-10 pr-10 py-2 text-sm text-gray-700 placeholder:text-gray-400 focus:outline-none focus:border-blue-600 hover:border-blue-600/50 disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-gray-50 transition-colors duration-200;
    }

    .input-icon-left {
        @apply absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 text-base pointer-events-none;
    }

    .input-icon-right {
        @apply absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-600 text-base cursor-pointer;
    }

    /* Input with Dropdown */
    .input-dropdown {
        @apply relative;
    }

    .input-dropdown-content {
        @apply absolute top-full left-0 mt-2 w-full rounded-xl border border-slate-200 bg-white shadow-xl opacity-0 invisible translate-y-[-10px] transition-all duration-200 ease-out z-50 max-h-[400px] overflow-y-auto;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    }

    .input-dropdown-content.show {
        @apply opacity-100 visible translate-y-0;
    }

    .input-dropdown-item {
        @apply flex items-center gap-3 px-4 py-3 text-[13px] font-medium text-slate-700 hover:bg-slate-50 border-b border-slate-50 last:border-0 cursor-pointer transition-colors duration-150;
    }

    .file-upload {
        @apply grid w-full items-center gap-1.5;
    }

    .file-upload label {
        @apply btn outline cursor-pointer;
    }

    .file-upload input {
        @apply sr-only;
    }

    .file-upload .file-status {
        @apply text-xs text-gray-600;
    }

    .checkbox-group, .radio-group {
        @apply space-y-3;
    }

    .checkbox-item, .radio-item {
        @apply flex items-center space-x-2;
    }

    /* Card style for checkbox */
    .checkbox-card {
        @apply flex items-center space-x-3 cursor-pointer transition-all duration-200;
        padding: var(--card-padding);
        border-radius: var(--card-border-radius);
        border-width: var(--card-border-width);
        background-color: var(--card-background);
        border-color: var(--card-border-color);
    }

    .checkbox-card:hover {
        border-color: var(--input-border-focus-color);
        box-shadow: var(--card-shadow);
    }

    .checkbox-card:has(.checkbox-input:checked) {
        border-color: var(--input-border-focus-color);
        background-color: var(--input-background);
    }

    /* Card style for radio */
    .radio-card {
        @apply flex items-center space-x-3 cursor-pointer transition-all duration-200;
        padding: var(--card-padding);
        border-radius: var(--card-border-radius);
        border-width: var(--card-border-width);
        background-color: var(--card-background);
        border-color: var(--card-border-color);
    }

    .radio-card:hover {
        border-color: var(--input-border-focus-color);
        box-shadow: var(--card-shadow);
    }

    .radio-card:has(.radio-input:checked) {
        border-color: var(--input-border-focus-color);
        background-color: var(--input-background);
    }

    /* Custom checkbox container */
    .checkbox-container {
        @apply relative flex items-center;
    }

    /* Hide default checkbox but keep it accessible */
    .checkbox-input {
        @apply sr-only;
    }

    /* Custom checkbox appearance */
    .checkbox-custom {
        @apply flex items-center justify-center transition-all duration-150 cursor-pointer;
        width: var(--checkbox-size);
        height: var(--checkbox-size);
        border-radius: var(--checkbox-border-radius);
        border-width: var(--checkbox-border-width);
        background-color: var(--checkbox-background);
        border-color: var(--checkbox-border-color);
    }

    /* Checkbox hover state */
    .checkbox-input:hover + .checkbox-custom {
        border-color: var(--checkbox-border-checked-color);
        opacity: 0.7;
    }

    /* Checkbox checked state */
    .checkbox-input:checked + .checkbox-custom {
        background-color: var(--checkbox-background-checked);
        border-color: var(--checkbox-border-checked-color);
    }

    /* Checkbox checkmark color */
    .checkbox-input:checked + .checkbox-custom .checkbox-icon {
        color: var(--checkbox-checkmark-color);
    }

    .checkbox-input:checked + .checkbox-custom[class*="border-green-500"] {
        background-color: #10b981 !important;
        border-color: #10b981 !important;
    }

    .checkbox-input:checked + .checkbox-custom[class*="border-red-500"] {
        background-color: #ef4444 !important;
        border-color: #ef4444 !important;
    }

    .checkbox-input:checked + .checkbox-custom[class*="border-purple-500"] {
        background-color: #8b5cf6 !important;
        border-color: #8b5cf6 !important;
    }

    .checkbox-input:checked + .checkbox-custom[class*="border-orange-500"] {
        background-color: #f97316 !important;
        border-color: #f97316 !important;
    }

    /* Checkbox disabled state */
    .checkbox-input:disabled + .checkbox-custom {
        @apply opacity-50 cursor-not-allowed;
    }

    /* Checkbox focus state */
    .checkbox-input:focus-visible + .checkbox-custom {
        @apply ring-2 ring-blue-600/30 ring-offset-1;
    }

    /* Checkbox checkmark icon - hidden by default */
    .checkbox-icon {
        @apply opacity-0 transition-opacity duration-150;
    }

    /* Show checkmark when checked */
    .checkbox-input:checked + .checkbox-custom .checkbox-icon {
        @apply opacity-100;
    }

    /* Custom radio container */
    .radio-container {
        @apply relative flex items-center;
    }

    /* Hide default radio but keep it accessible */
    .radio-input {
        @apply sr-only;
    }

    /* Custom radio appearance */
    .radio-custom {
        @apply flex items-center justify-center rounded-full transition-all duration-150 cursor-pointer;
        width: var(--radio-size);
        height: var(--radio-size);
        border-width: var(--radio-border-width);
        background-color: var(--radio-background);
        border-color: var(--radio-border-color);
    }

    /* Radio hover state */
    .radio-input:hover + .radio-custom {
        border-color: var(--radio-border-checked-color);
        opacity: 0.7;
    }

    /* Radio checked state */
    .radio-input:checked + .radio-custom {
        border-color: var(--radio-border-checked-color);
    }

    /* Radio disabled state */
    .radio-input:disabled + .radio-custom {
        @apply opacity-50 cursor-not-allowed;
    }

    /* Radio focus state */
    .radio-input:focus-visible + .radio-custom {
        @apply ring-2 ring-offset-1;
        ring-color: var(--radio-border-checked-color);
        opacity: 0.3;
    }

    /* Radio inner dot - hidden by default */
    .radio-dot {
        @apply rounded-full scale-0 transition-transform duration-150;
        width: calc(var(--radio-size) / 2);
        height: calc(var(--radio-size) / 2);
        background-color: var(--radio-dot-color);
    }

    /* Show inner dot when checked */
    .radio-input:checked + .radio-custom .radio-dot {
        @apply scale-100;
    }

    /* Legacy support for older checkbox/radio usage */
    .checkbox, .radio {
        @apply h-4 w-4 rounded-sm border border-blue-600 ring-offset-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-50 accent-blue-600 transition-colors;
    }

    .radio {
        @apply rounded-full;
    }

    /* Button Components */
    .btn {
        @apply inline-flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-200 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 shadow-sm active:scale-[0.98];
        height: var(--btn-height);
        padding: var(--btn-padding-y) var(--btn-padding-x);
        font-size: var(--btn-font-size);
        font-weight: var(--btn-font-weight);
        border-radius: var(--btn-border-radius);
        border-width: var(--btn-border-width);
        background-color: var(--btn-background);
        color: var(--btn-text-color);
        border-color: var(--btn-border-color);
    }

    .btn:hover:not(:disabled) {
        background-color: var(--btn-background-hover);
    }

    /* Modern Color Variants */
    .btn.white {
        @apply bg-white text-gray-900 hover:bg-gray-50 border border-gray-200 shadow-none;
    }

    .btn.light {
        @apply bg-gray-50 text-gray-700 hover:text-gray-900 hover:bg-gray-100 border border-gray-200 shadow-none;
    }

    .btn.emerald {
        @apply bg-emerald-500 text-white hover:bg-emerald-600;
    }

    .btn.rose {
        @apply bg-rose-500 text-white hover:bg-rose-600;
    }

    .btn.slate {
        @apply bg-slate-800 text-white hover:bg-slate-900;
    }

    .btn.amber {
        @apply bg-amber-500 text-slate-900 hover:bg-amber-600;
    }

    .btn.violet {
        @apply bg-violet-500 text-white hover:bg-violet-600;
    }

    .btn.cyan {
        @apply bg-cyan-500 text-white hover:bg-cyan-600;
    }

    /* Style Variants */
    .btn.secondary {
        @apply bg-gray-600 text-white hover:bg-gray-700;
    }

    .btn.outline {
        @apply border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 hover:border-gray-300 shadow-none active:scale-[0.98] transition-all duration-200;
    }

    .btn.ghost {
        @apply text-gray-700 hover:bg-gray-100 hover:text-gray-900 shadow-none active:scale-[0.98] transition-all duration-200;
    }

    /* Loading button animations */
    .btn.loading {
        @apply opacity-75 cursor-not-allowed pointer-events-none;
    }

    .btn:active {
        @apply scale-95 transition-transform duration-75;
    }

    /* Dropdown button styles */
    .btn-dropdown {
        @apply relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:pointer-events-none disabled:opacity-50 h-10 px-5 py-2 bg-blue-600 text-white hover:bg-blue-700 active:scale-95;
    }

    .btn-dropdown.outline {
        @apply border border-gray-200 bg-white text-gray-900 hover:bg-gray-50 hover:text-gray-900 hover:border-gray-300;
    }

    .btn-dropdown.secondary {
        @apply bg-gray-600 text-white hover:bg-gray-700;
    }

    /* Dropdown content */
    .dropdown-content {
        @apply absolute top-full left-0 mt-2 min-w-full rounded-2xl border border-gray-200 bg-background shadow-xl opacity-0 invisible translate-y-[-8px] transition-all duration-200 ease-out z-50;
    }

    .dropdown-content.show {
        @apply opacity-100 visible translate-y-0;
    }

    .dropdown-menu {
        @apply p-1;
    }

    .dropdown-item {
        @apply flex items-center px-4 py-2.5 text-sm text-foreground hover:bg-accent rounded-xl transition-colors cursor-pointer;
    }

    .dropdown-item.with-icon {
        @apply gap-2;
    }

    .dropdown-separator {
        @apply border-t border-border my-1;
    }

    .dropdown-submenu {
        @apply relative;
    }

    .dropdown-submenu .dropdown-content {
        @apply top-0 left-full ml-1;
    }

    .btn-sm {
        @apply h-9 px-4 text-xs;
        border-radius: var(--btn-border-radius);
    }

    .btn-lg {
        @apply h-12 px-8 text-base;
        border-radius: var(--btn-border-radius);
    }

    .button-group {
        @apply flex flex-wrap gap-2;
    }

    /* =============f===================
       CARD COMPONENTS SYSTEM
       ================================ */

    /* Base Card Structure */
    .card {
        @apply overflow-hidden;
        padding: var(--card-padding);
        border-radius: var(--card-border-radius);
        border-width: var(--card-border-width);
        background-color: var(--card-background);
        border-color: var(--card-border-color);
        /* box-shadow: var(--card-shadow); */
    }

    .cutom-card {
        padding: var(--card-padding);
        border-radius: var(--card-border-radius);
        border-width: var(--card-border-width);
        background-color: var(--card-background);
        border-color: var(--card-border-color);
    }

    /* Footer Styles */
    footer a {
        color: var(--footer-link-color);
        transition: color 0.3s ease;
    }

    footer a:hover {
        color: var(--footer-link-hover-color);
    }

    footer h3, footer h4, footer h5 {
        color: var(--footer-heading-color);
    }

    /* Header font size - override Tailwind classes */
    header, header *, header nav, header nav *, header a, header button, header span:not(.material-symbols-outlined) {
        font-size: var(--header-font-size) !important;
    }

    /* Footer font size - override Tailwind classes */
    footer, footer *, footer a, footer p, footer span:not(.material-symbols-outlined), footer div {
        font-size: var(--footer-font-size) !important;
    }

    .card-header {
        @apply px-[15px] py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 h-[44px];
        @apply flex items-center justify-between;
    }

    .card-header-responsive {
        @apply px-[15px] border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 !important;
        @apply flex flex-col items-start justify-center gap-1 py-2.5 min-h-[44px] h-auto !important;
        @apply sm:flex-row sm:items-center sm:justify-between sm:h-[44px] sm:py-0 !important;
    }

    .card-header > div:first-child,
    .card-header-responsive > div:first-child {
        @apply flex items-center gap-2;
    }

    .card-header > div:last-child,
    .card-header-responsive > div:last-child {
        @apply text-xs text-gray-500 dark:text-gray-400 py-1 rounded-md;
    }

    .card-header .card-header-icon,
    .card-header-responsive .card-header-icon {
        @apply text-gray-600 dark:text-gray-400 text-lg;
        font-family: 'Material Symbols Outlined';
        font-weight: normal;
        font-style: normal;
        font-size: 20px;
        line-height: 1;
        letter-spacing: normal;
        text-transform: none;
        display: inline-block;
        white-space: nowrap;
        word-wrap: normal;
        direction: ltr;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        text-rendering: optimizeLegibility;
        font-feature-settings: 'liga';
    }

    .card-header h3,
    .card-header-responsive h3 {
        @apply text-[14px] font-semibold text-gray-900 dark:text-gray-100;
    }

    /* Card Body */
    .card-body {
        @apply p-4;
    }

    /* Card Footer */
    .card-footer {
        @apply px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50;
    }

    /* Colorful Gradient Cards */
    .card-blue {
        @apply bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 border-blue-200 dark:border-blue-700;
    }

    .card-blue .card-icon {
        @apply text-blue-600 dark:text-blue-400;
    }

    .card-blue .card-title {
        @apply text-blue-900 dark:text-blue-100;
    }

    .card-blue .card-text {
        @apply text-blue-700 dark:text-blue-300;
    }

    .card-green {
        @apply bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 border-green-200 dark:border-green-700;
    }

    .card-green .card-icon {
        @apply text-green-600 dark:text-green-400;
    }

    .card-green .card-title {
        @apply text-green-900 dark:text-green-100;
    }

    .card-green .card-text {
        @apply text-green-700 dark:text-green-300;
    }

    .card-purple {
        @apply bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-800/20 border-purple-200 dark:border-purple-700;
    }

    .card-purple .card-icon {
        @apply text-purple-600 dark:text-purple-400;
    }

    .card-purple .card-title {
        @apply text-purple-900 dark:text-purple-100;
    }

    .card-purple .card-text {
        @apply text-purple-700 dark:text-purple-300;
    }

    .card-orange {
        @apply bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900/20 dark:to-orange-800/20 border-orange-200 dark:border-orange-700;
    }

    .card-orange .card-icon {
        @apply text-orange-600 dark:text-orange-400;
    }

    .card-orange .card-title {
        @apply text-orange-900 dark:text-orange-100;
    }

    .card-orange .card-text {
        @apply text-orange-700 dark:text-orange-300;
    }

    .card-red {
        @apply bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900/20 dark:to-red-800/20 border-red-200 dark:border-red-700;
    }

    .card-red .card-icon {
        @apply text-red-600 dark:text-red-400;
    }

    .card-red .card-title {
        @apply text-red-900 dark:text-red-100;
    }

    .card-red .card-text {
        @apply text-red-700 dark:text-red-300;
    }

    .card-indigo {
        @apply bg-gradient-to-br from-indigo-50 to-indigo-100 dark:from-indigo-900/20 dark:to-indigo-800/20 border-indigo-200 dark:border-indigo-700;
    }

    .card-indigo .card-icon {
        @apply text-indigo-600 dark:text-indigo-400;
    }

    .card-indigo .card-title {
        @apply text-indigo-900 dark:text-indigo-100;
    }

    .card-indigo .card-text {
        @apply text-indigo-700 dark:text-indigo-300;
    }

    .card-pink {
        @apply bg-gradient-to-br from-pink-50 to-pink-100 dark:from-pink-900/20 dark:to-pink-800/20 border-pink-200 dark:border-pink-700;
    }

    .card-pink .card-icon {
        @apply text-pink-600 dark:text-pink-400;
    }

    .card-pink .card-title {
        @apply text-pink-900 dark:text-pink-100;
    }

    .card-pink .card-text {
        @apply text-pink-700 dark:text-pink-300;
    }

    .card-teal {
        @apply bg-gradient-to-br from-teal-50 to-teal-100 dark:from-teal-900/20 dark:to-teal-800/20 border-teal-200 dark:border-teal-700;
    }

    .card-teal .card-icon {
        @apply text-teal-600 dark:text-teal-400;
    }

    .card-teal .card-title {
        @apply text-teal-900 dark:text-teal-100;
    }

    .card-teal .card-text {
        @apply text-teal-700 dark:text-teal-300;
    }

    /* Card Content Elements */
    .card-icon-wrapper {
        @apply flex items-center mb-2;
    }

    .card-icon-wrapper .material-symbols-outlined {
        @apply mr-2;
    }

    .card-title {
        @apply font-semibold text-gray-900 dark:text-gray-100;
    }

    .card-text {
        @apply text-sm text-gray-700 dark:text-gray-300;
    }

    .card-description {
        @apply text-sm text-gray-600 dark:text-gray-400 mt-1;
    }

    /* Card with Image */
    .card-image {
        @apply w-full h-48 object-cover;
    }

    /* Card Hover Effects */
    .card-hover {
        @apply transition-all duration-200 hover:shadow-lg hover:-translate-y-1;
    }

    .card-clickable {
        @apply cursor-pointer transition-all duration-200 hover:shadow-lg hover:border-blue-300 dark:hover:border-blue-600;
    }

    /* Card Info List */
    .card-info-list {
        @apply space-y-3;
    }

    .card-info-item {
        @apply flex justify-between text-sm;
    }

    .card-info-label {
        @apply text-gray-500 dark:text-gray-400;
    }

    .card-info-value {
        @apply font-medium text-gray-900 dark:text-gray-100;
    }

    /* Card Actions */
    .card-actions {
        @apply flex items-center justify-between gap-2 mt-4;
    }

    /* Card Stats */
    .card-stat {
        @apply text-center p-4;
    }

    .card-stat-value {
        @apply text-2xl font-bold text-gray-900 dark:text-gray-100;
    }

    .card-stat-label {
        @apply text-sm text-gray-600 dark:text-gray-400 mt-1;
    }

    /* Cards Grid Layout */
    .cards-grid {
        @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4;
    }

    .cards-grid-2 {
        @apply grid grid-cols-1 md:grid-cols-2 gap-4;
    }

    .cards-grid-4 {
        @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4;
    }

    /* Alert Components */
    .alert-success {
        @apply relative w-full rounded-3xl border border-green-200 bg-green-50 text-green-800 flex items-center gap-3;
        padding: 14px 24px;
    }

    .alert-error {
        @apply relative w-full rounded-3xl border border-red-200 bg-red-50 text-red-800 flex items-center gap-3;
        padding: 14px 24px;
    }

    .alert-warning {
        @apply relative w-full rounded-3xl border border-yellow-200 bg-yellow-50 text-yellow-800 flex items-center gap-3;
        padding: 14px 24px;
    }

    .alert-info {
        @apply relative w-full rounded-3xl border border-blue-200 bg-blue-50 text-blue-800 flex items-center gap-3;
        padding: 14px 24px;
    }

    /* Material Icon styling for alerts */
    .alert-success .material-icon,
    .alert-error .material-icon,
    .alert-warning .material-icon,
    .alert-info .material-icon {
        @apply flex-shrink-0;
        font-size: 20px;
        line-height: 1;
    }

    /* Alert text styling */
    .alert-success p,
    .alert-error p,
    .alert-warning p,
    .alert-info p {
        @apply text-sm opacity-90 mt-0 mb-0 flex-1;
        line-height: 1.4;
    }

    /* Badge Components */
    .badge {
        @apply inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-blue-600;
    }

    .badge-primary {
        @apply border-transparent bg-blue-600 text-white hover:bg-blue-700;
    }

    .badge-secondary {
        @apply border-transparent bg-gray-600 text-white hover:bg-gray-700;
    }

    .badge-outline {
        @apply text-gray-900 border-gray-200 bg-transparent hover:bg-gray-100;
    }

    .badge-success {
        @apply bg-green-100 text-green-800 border-green-200 hover:bg-green-200;
    }

    .badge-warning {
        @apply bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200;
    }

    .badge-error {
        @apply bg-red-100 text-red-800 border-red-200 hover:bg-red-200;
    }

    .badge-info {
        @apply bg-blue-100 text-blue-800 border-blue-200 hover:bg-blue-200;
    }

    .badge-purple {
        @apply bg-purple-100 text-purple-800 border-purple-200 hover:bg-purple-200;
    }

    .badge-pink {
        @apply bg-pink-100 text-pink-800 border-pink-200 hover:bg-pink-200;
    }

    .badge-indigo {
        @apply bg-indigo-100 text-indigo-800 border-indigo-200 hover:bg-indigo-200;
    }

    .badge-gray {
        @apply bg-gray-100 text-gray-800 border-gray-200 hover:bg-gray-200;
    }

    /* Badge Sizes */
    .badge-sm {
        @apply px-2 py-0.5 text-xs;
    }

    .badge-md {
        @apply px-2.5 py-0.5 text-xs;
    }

    .badge-lg {
        @apply px-3 py-1 text-sm;
    }

    /* Badge Variants */
    .badge-solid {
        @apply border-transparent;
    }

    .badge-soft {
        @apply border-transparent opacity-90;
    }

    .badge-outlined {
        @apply bg-transparent border-2;
    }

    .badge-ghost {
        @apply bg-transparent border-transparent;
    }

    /* Badge with Icons */
    .badge-with-icon {
        @apply gap-1.5;
    }

    .badge-icon {
        @apply w-3 h-3 flex-shrink-0;
    }

    /* Badge Group */
    .badge-group {
        @apply flex flex-wrap gap-2;
    }

    /* Badge Removable */
    .badge-removable {
        @apply pr-1;
    }

    .badge-remove {
        @apply ml-1 h-3 w-3 rounded-full flex items-center justify-center hover:bg-black/10 transition-colors cursor-pointer;
    }

    /* Badge Status Dots */
    .badge-dot {
        @apply relative pl-6;
    }

    .badge-dot::before {
        @apply absolute left-2 top-1/2 transform -translate-y-1/2 w-2 h-2 rounded-full;
        content: '';
    }

    .badge-dot.badge-success::before {
        @apply bg-green-500;
    }

    .badge-dot.badge-warning::before {
        @apply bg-yellow-500;
    }

    .badge-dot.badge-error::before {
        @apply bg-red-500;
    }

    .badge-dot.badge-info::before {
        @apply bg-blue-500;
    }

    /* Badge Pulse Animation */
    .badge-pulse {
        @apply animate-pulse;
    }

    .badge-ping::before {
        @apply absolute top-0 left-0 w-full h-full rounded-full animate-ping;
        content: '';
    }

    /* Progress Components */
    .progress-item {
        @apply space-y-2;
    }

    .progress-label {
        @apply flex justify-between text-sm text-gray-900;
    }

    .progress-bar {
        @apply w-full bg-gray-200 rounded-full h-2 overflow-hidden;
    }

    .progress-fill {
        @apply h-2 rounded-full transition-all duration-500 ease-out;
    }

    .progress-fill-primary {
        @apply bg-blue-600;
    }

    .progress-fill-success {
        @apply bg-green-500;
    }

    .progress-fill-warning {
        @apply bg-yellow-500;
    }

    .progress-fill-error {
        @apply bg-red-500;
    }

    /* Tab Components */
    .tabs-container {
        @apply w-full min-w-0 overflow-hidden;
    }

    .tabs-list {
        @apply flex h-10 w-full max-w-full flex-nowrap items-center justify-start overflow-x-auto rounded-md bg-gray-100 p-1 text-gray-600;
        scrollbar-width: thin;
        -webkit-overflow-scrolling: touch;
    }

    .tabs-trigger {
        @apply inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-white transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 cursor-pointer gap-1;
    }

    .tabs-trigger.active {
        @apply bg-white text-gray-900 shadow-sm;
    }

    .tabs-content {
        @apply mt-4 ring-offset-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 hidden;
    }

    .tabs-content.active {
        @apply block;
    }

    .tabs-content h3 {
        @apply text-lg font-medium text-gray-900;
    }

    .tabs-content p {
        @apply text-sm text-gray-600 mt-1;
    }

    /* Accordion Components */
    .accordion {
        @apply w-full divide-y divide-gray-200;
    }

    .accordion-item {
        @apply py-4;
    }

    .accordion-trigger {
        @apply flex w-full items-center justify-between font-medium transition-all hover:underline text-left focus-visible:outline-none text-gray-900 cursor-pointer;
    }

    .accordion-icon {
        @apply text-sm shrink-0 transition-transform duration-200;
    }

    .accordion-content {
        @apply overflow-hidden pb-4 pt-2 hidden;
    }

    .accordion-content p {
        @apply text-sm text-gray-600;
    }

    /* Table Components */
    .table-container {
        @apply overflow-x-auto;
    }

    .table {
        @apply min-w-full border border-gray-200 rounded-md;
    }

    .table thead {
        @apply bg-gray-100;
    }

    .table th {
        @apply px-4 py-2 text-left text-sm font-medium text-gray-900 border-b border-gray-200;
    }

    .table td {
        @apply px-4 py-2 text-sm text-gray-900 border-b border-gray-200/50;
    }

    .table tbody tr {
        @apply hover:bg-muted/50 transition-colors;
    }

    /* Radio Button Enhanced Components */
    .radio-card {
        @apply w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md bg-white;
    }

    .radio-card.selected {
        @apply border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200;
    }

    .radio-card:hover {
        @apply border-gray-300;
    }

    .radio-card.selected:hover {
        @apply border-blue-500;
    }

    .radio-icon-group {
        @apply flex items-center space-x-2;
    }

    .radio-content {
        @apply flex-1;
    }

    .radio-title {
        @apply font-semibold text-gray-900 text-base cursor-pointer;
    }

    .radio-description {
        @apply text-sm text-gray-600 mt-1;
    }

    .radio-status-box {
        @apply p-4 rounded-lg border;
    }

    .radio-status-box.primary {
        @apply bg-gradient-to-r from-blue-50 to-indigo-50 border-blue-200;
    }

    .radio-status-box.secondary {
        @apply bg-gradient-to-r from-indigo-50 to-purple-50 border-indigo-200;
    }

    /* Switch Components */
    .switch-container {
        @apply flex items-center cursor-pointer;
    }

    .switch-container.disabled {
        @apply cursor-not-allowed opacity-50;
    }

    /* Hide default checkbox */
    .switch-input {
        @apply sr-only;
    }

    /* Switch Track Base */
    .switch-track {
        @apply relative bg-gray-200 rounded-full transition-all duration-200 ease-in-out;
    }

    .switch-track:focus-within {
        @apply ring-4 ring-blue-300 ring-opacity-50;
    }

    /* Switch Thumb Base */
    .switch-thumb {
        @apply absolute bg-white border border-gray-300 rounded-full transition-all duration-200 ease-in-out shadow-sm;
    }

    /* Switch States */
    .switch-input:checked + .switch-track {
        @apply bg-blue-600;
    }

    .switch-input:checked + .switch-track .switch-thumb {
        @apply border-white;
    }

    .switch-input:disabled + .switch-track {
        @apply cursor-not-allowed opacity-50;
    }

    /* Switch Sizes */
    .switch-sm .switch-track {
        @apply w-8 h-4;
    }

    .switch-sm .switch-thumb {
        @apply w-3 h-3 top-0.5 left-0.5;
    }

    .switch-sm .switch-input:checked + .switch-track .switch-thumb {
        @apply translate-x-4;
    }

    .switch-md .switch-track {
        @apply w-11 h-6;
    }

    .switch-md .switch-thumb {
        @apply w-5 h-5 top-0.5 left-0.5;
    }

    .switch-md .switch-input:checked + .switch-track .switch-thumb {
        @apply translate-x-5;
    }

    .switch-lg .switch-track {
        @apply w-14 h-8;
    }

    .switch-lg .switch-thumb {
        @apply w-7 h-7 top-0.5 left-0.5;
    }

    .switch-lg .switch-input:checked + .switch-track .switch-thumb {
        @apply translate-x-6;
    }

    .switch-xl .switch-track {
        @apply w-16 h-10;
    }

    .switch-xl .switch-thumb {
        @apply w-9 h-9 top-0.5 left-0.5;
    }

    .switch-xl .switch-input:checked + .switch-track .switch-thumb {
        @apply translate-x-7;
    }

    /* Switch Colors */
    .switch-blue .switch-input:checked + .switch-track {
        @apply bg-blue-600;
    }

    .switch-blue .switch-track:focus-within {
        @apply ring-blue-300;
    }

    .switch-green .switch-input:checked + .switch-track {
        @apply bg-green-600;
    }

    .switch-green .switch-track:focus-within {
        @apply ring-green-300;
    }

    .switch-red .switch-input:checked + .switch-track {
        @apply bg-red-600;
    }

    .switch-red .switch-track:focus-within {
        @apply ring-red-300;
    }

    .switch-purple .switch-input:checked + .switch-track {
        @apply bg-purple-600;
    }

    .switch-purple .switch-track:focus-within {
        @apply ring-purple-300;
    }

    .switch-yellow .switch-input:checked + .switch-track {
        @apply bg-yellow-500;
    }

    .switch-yellow .switch-track:focus-within {
        @apply ring-yellow-300;
    }

    .switch-pink .switch-input:checked + .switch-track {
        @apply bg-pink-600;
    }

    .switch-pink .switch-track:focus-within {
        @apply ring-pink-300;
    }

    .switch-indigo .switch-input:checked + .switch-track {
        @apply bg-indigo-600;
    }

    .switch-indigo .switch-track:focus-within {
        @apply ring-indigo-300;
    }

    .switch-teal .switch-input:checked + .switch-track {
        @apply bg-teal-600;
    }

    .switch-teal .switch-track:focus-within {
        @apply ring-teal-300;
    }

    .switch-orange .switch-input:checked + .switch-track {
        @apply bg-orange-600;
    }

    .switch-orange .switch-track:focus-within {
        @apply ring-orange-300;
    }

    /* Switch Label */
    .switch-label {
        @apply ml-3 text-sm font-medium text-gray-700 select-none;
    }

    .switch-container.disabled .switch-label {
        @apply text-gray-400;
    }

    /* Switch with Icons */
    .switch-with-icons .switch-track {
        @apply w-12;
    }

    .switch-with-icons .switch-thumb {
        @apply flex items-center justify-center;
    }

    .switch-icon-left {
        @apply absolute left-1.5 top-1/2 transform -translate-y-1/2 text-xs text-gray-500 transition-opacity;
    }

    .switch-icon-right {
        @apply absolute right-1.5 top-1/2 transform -translate-y-1/2 text-xs text-white transition-opacity opacity-0;
    }

    .switch-input:checked + .switch-track .switch-icon-left {
        @apply opacity-0;
    }

    .switch-input:checked + .switch-track .switch-icon-right {
        @apply opacity-100;
    }

    /* Switch Variants */
    .switch-outlined .switch-track {
        @apply bg-white border-2 border-gray-300;
    }

    .switch-outlined .switch-thumb {
        @apply bg-blue-600 border-blue-600 top-0.5 left-0.5;
    }

    .switch-outlined .switch-input:checked + .switch-track {
        @apply bg-blue-50 border-blue-600;
    }

    .switch-outlined .switch-input:checked + .switch-track .switch-thumb {
        @apply bg-blue-600 border-blue-600;
    }

    .switch-rounded-square .switch-track {
        @apply rounded-md;
    }

    .switch-rounded-square .switch-thumb {
        @apply rounded-sm;
    }

    .switch-gradient .switch-input:checked + .switch-track {
        @apply bg-gradient-to-r from-blue-500 to-purple-600;
    }

    .switch-shadow .switch-track {
        @apply shadow-lg;
    }

    .switch-shadow .switch-thumb {
        @apply shadow-lg;
    }

    /* Dropdown Components */
    .dropdown {
        @apply relative inline-block;
    }

    .dropdown-content {
        @apply absolute top-full left-0 mt-1 w-44 rounded-md border border-gray-200 bg-white shadow-lg opacity-0 invisible translate-y-[-8px] transition-all duration-150 ease-out z-50;
    }

    .dropdown-content.show {
        @apply opacity-100 visible translate-y-0;
    }

    .dropdown-menu {
        @apply p-1;
    }

    .dropdown-item {
        @apply block px-2 py-1.5 text-sm text-gray-900 hover:bg-gray-100 rounded transition-colors cursor-pointer;
    }

    .dropdown-separator {
        @apply border-t border-gray-200 my-1;
    }

    /* Modal Components */
    .modal-overlay {
        @apply fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden;
    }

    .modal-content {
        @apply bg-white rounded-lg max-w-md w-full border border-gray-200 shadow-lg;
    }

    .modal-header {
        @apply flex items-center justify-between p-4 border-b border-gray-200;
    }

    .modal-header h3 {
        @apply text-lg font-semibold text-gray-900;
    }

    .modal-body {
        @apply p-4;
    }

    .modal-body p {
        @apply text-sm text-gray-600 mb-4;
    }

    .modal-footer {
        @apply flex justify-end gap-2;
    }

    /* Tooltip Components */
    .tooltip {
        @apply absolute z-50 px-2 py-1 text-xs text-white bg-gray-900 rounded shadow-lg -top-8 left-1/2 transform -translate-x-1/2 whitespace-nowrap;
    }
}
</style>