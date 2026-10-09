<?php

/**
 * Updates System Configuration
 *
 * Single centralized configuration file for the entire updates system.
 * This file contains GitHub credentials, update settings, and system preferences.
 *
 * @package v10
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('SECURE') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    die('Access Denied!');
}

// GET VERSION FROM DATABASE
$v = $db->get("settings", "version", ["id" => 1]);

return [

    // ==================================================
    // GITHUB REPOSITORY SETTINGS
    // ==================================================

    /**
     * GitHub repository in format: owner/repo
     */
    'github_repo' => '',
    'github_token' => '',

    /**
     * Default branch to check for updates
     * Usually: 'main' or 'master'
     */
    'default_branch' => 'main',

    // ==================================================
    // UPDATE FILTERING & BEHAVIOR
    // ==================================================

    /**
     * Only show updates after this date
     * Format: 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS'
     * Set to null to show all updates
     * Example: '2025-01-01' will only show updates from Jan 1, 2025 onwards
     */
    'updates_start_date' => '2026-08-03',

    /**
     * Sequential installation mode
     * When true: Only allows installing updates in chronological order (oldest first)
     * When false: Allows installing any update regardless of order
     */
    'sequential_installation' => true,

    // ==================================================
    // VERSION & SYSTEM INFO
    // ==================================================

    /**
     * Current application version
     * Use semantic versioning: MAJOR.MINOR.PATCH
     */

    'current_version' => $v,

    // ==================================================
    // BACKUP SETTINGS
    // ==================================================

    /**
     * Automatically backup files before update
     */
    'auto_backup' => true,

    /**
     * Backup directory path (relative to root)
     */
    'backup_dir' => 'backups/updates',

    /**
     * Maximum number of backups to keep
     * Older backups will be automatically deleted
     */
    'max_backups' => 10,

    // ==================================================
    // SECURITY & EXCLUSIONS
    // ==================================================

    /**
     * Require administrator confirmation before installation
     */
    'require_confirmation' => true,

    /**
     * Files/directories to exclude from updates
     * These will never be replaced during updates
     */
    'excluded_files' => [
        'config.php',
        'app/views/admin/updates-config.php',
        'app/updates.json',
        '.env',
        '.htaccess',
        'uploads/',
        'cache/',
        'backups/',
    ],

    /**
     * Verify SSL certificates when connecting to GitHub
     */
    'verify_ssl' => true,

    /**
     * Allowed update sources
     * Only updates from these repositories will be accepted
     */
    'allowed_repos' => [
        ''
    ],

    // ==================================================
    // NOTIFICATIONS & AUTOMATION
    // ==================================================

    /**
     * Enable update notifications
     */
    'notifications_enabled' => true,

    /**
     * Check for updates automatically (requires cron setup)
     */
    'auto_check' => false,

    /**
     * Auto-check interval in seconds
     * Default: 86400 (24 hours)
     */
    'check_interval' => 86400,

    /**
     * Require signature verification (future feature)
     */
    'require_signature' => false,

];
