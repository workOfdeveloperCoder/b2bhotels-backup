<?php
/**
 * Ultra-Basic CRUD Library - Minimal Code
 *
 * Usage:
 * $crud->table('users')
 *      ->col('id,first_name,last_name,email')  // Specify which columns to display
 *      ->title('Users')
 *      ->perPage(25)
 *      ->actions(['add' => true, 'view' => false, 'delete' => false])  // Control action buttons
 *      ->render();
 */

class CRUD {
    public $table, $db, $title = 'Records', $perPage = 25, $columns = [];
    public $actions = [
        'add' => true,
        'status' => true,
        'view' => true,
        'edit' => true,
        'delete' => true,
        'search' => true,
        'bulk_delete' => true
    ];
    public $action_urls = [
        'add' => null,
        'view' => null,
        'edit' => null,
        'delete' => null
    ];
    public $id_column = 'id'; // Column to use for URLs (edit, view, delete)
    public $where_conditions = []; // Where conditions for filtering
    public $order_by = []; // Order by conditions
    public $col_widths = []; // Column width settings
    public $col_labels = []; // Custom column labels
    public $row_formats = []; // Custom row data formatting
    public $relations = []; // Relations to other tables
    public $custom_buttons = []; // Custom action buttons
    public $action_icons = []; // Override icons for built-in action buttons
    public $protected_records = []; // IDs of records that cannot be deleted
    public $extra_fetch_cols = []; // Extra columns to fetch for placeholder use (not displayed)
    public $list_url = ''; // List page URL (search form + clear), e.g. /admin/settings#ai

    // ID column mapping for different tables
    private static $id_columns = [
        'users' => 'user_id',
        'bookings' => 'id',
        // Add more tables with custom primary keys here
    ];

    /**
     * Handle AJAX requests for CRUD operations
     * Call this method in your AJAX route handler
     */
    public static function handleAjax($db) {
        try {
            // Clean any output buffer and start fresh
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_start();

            // Set JSON header first
            header('Content-Type: application/json');

            // Get JSON input
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON input: ' . json_last_error_msg());
            }

            $action = $input['action'] ?? '';
            $table = $input['table'] ?? '';
            $id = $input['id'] ?? 0;

            if (empty($action)) {
                throw new Exception('Action parameter is required');
            }

            if (empty($table)) {
                throw new Exception('Table parameter is required');
            }

            // Get the ID column for the table
            $id_column = self::$id_columns[$table] ?? 'id';

            // Validate table name
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new Exception('Invalid table name format: ' . $table);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
            ]);
            exit();
        }

        // Handle toggle status action
        if ($action === 'toggle_status') {
            try {
                $column = $input['column'] ?? 'status';
                $status = $input['status'] ?? 0;

                if (empty($id)) {
                    throw new Exception('ID parameter is required for toggle_status action');
                }

                // SECURITY: Check if trying to disable a default gateway
                if ($status == 0) {  // Only check if disabling (status = 0)
                    $record = $db->get($table, '*', [$id_column => $id]);
                    if ($record && isset($record['default']) && $record['default'] == 1) {
                        ob_end_clean();
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Cannot disable a payment gateway that is set as default. Please set another gateway as default first.'
                        ]);
                        exit();
                    }
                }

                $valueToSet = $status;

                $current = $db->get($table, [$column], [$id_column => $id]);
                $currentValue = $current[$column] ?? null;

                if ($currentValue !== null) {
                    if (is_string($currentValue)) {
                        if ($currentValue === 'active' || $currentValue === 'inactive') {
                            $valueToSet = $status == 1 ? 'active' : 'inactive';
                        } elseif (in_array(strtolower($currentValue), ['yes', 'no', 'true', 'false'])) {
                            $valueToSet = $status == 1 ? 'yes' : 'no';
                        } elseif ($currentValue === '1' || $currentValue === '0') {
                            $valueToSet = (string)$status;
                        }
                    }
                }

                $result = $db->update($table, [$column => $valueToSet], [$id_column => $id]);

                if ($result === false) {
                    throw new Exception('Database update failed for table: ' . $table . ', column: ' . $column . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Status updated successfully',
                    'data' => ['id' => $id, 'status' => $valueToSet, 'column' => $column]
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Toggle status error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id, 'column' => $column],
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
                ]);
            }
            exit();
        }

        if ($action === 'set_default') {
            try {
                $id = (int)$id;

                if ($id <= 0) {
                    throw new Exception('Invalid ID for set_default action: ' . $id);
                }

                // Get the record with status/active field
                $record = $db->get($table, '*', [$id_column => $id]);
                if (!$record) {
                    throw new Exception('Record not found in table: ' . $table . ' with ID: ' . $id);
                }

                // SECURITY: Check if gateway is disabled - prevent setting disabled gateway as default
                $statusField = in_array('status', array_keys($record)) ? 'status' : (in_array('active', array_keys($record)) ? 'active' : null);
                if ($statusField && isset($record[$statusField]) && $record[$statusField] != 1) {
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => 'Cannot set a disabled payment gateway as default']);
                    exit();
                }

                if (isset($record['default']) && $record['default'] == 1) {
                    ob_end_clean();
                    echo json_encode(['status' => 'success', 'message' => 'Already set as default']);
                    exit();
                }

                // Reset all records to default = 0 using raw SQL
                try {
                    $db->exec("UPDATE " . $table . " SET `default` = 0");
                } catch (Exception $ex) {
                    // Fallback for different quote styles
                    $db->exec("UPDATE " . $table . " SET default = 0");
                }

                if ($table === 'currencies') {
                    $result = $db->update($table, ['default' => 1, 'rate' => 1.00], [$id_column => $id]);
                } else {
                    $result = $db->update($table, ['default' => 1], [$id_column => $id]);
                }

                if ($result === false) {
                    throw new Exception('Failed to set default for table: ' . $table . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Default updated successfully'
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Set default error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id],
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
                ]);
            }
            exit();
        }

        // Handle check default action (before delete)
        if ($action === 'check_default') {
            try {
                if (empty($id)) {
                    throw new Exception('ID parameter is required for check_default action');
                }

                $record = $db->get($table, ['default'], [$id_column => $id]);

                if ($record && isset($record['default']) && ($record['default'] == '1' || $record['default'] == 1)) {
                    ob_end_clean();
                    echo json_encode([
                        'status' => 'warning',
                        'is_default' => true,
                        'message' => 'Cannot delete the default record. Please set another record as default first.'
                    ]);
                    exit();
                }
            } catch (Exception $e) {
                // Table doesn't have 'default' column - this is OK
                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'is_default' => false,
                    'note' => 'No default column in table: ' . $table
                ]);
                exit();
            }

            ob_end_clean();
            echo json_encode(['status' => 'success', 'is_default' => false]);
            exit();
        }

        // Handle delete action
        if ($action === 'delete_record') {
            try {
                if (empty($id)) {
                    throw new Exception('ID parameter is required for delete_record action');
                }

                // Prevent deleting admin user
                if ($table === 'users' && $id == 1) {
                    throw new Exception('Cannot delete the admin user (ID: 1)');
                }

                // Prevent deleting system roles
                if ($table === 'users_roles' && in_array($id, [1, 2, 3, 4, 5])) {
                    throw new Exception('Cannot delete system role (ID: ' . $id . '). This role is required for the system.');
                }
                // Check if default record
                try {
                    $record = $db->get($table, ['default'], [$id_column => $id]);
                    if ($record && isset($record['default']) && ($record['default'] == '1' || $record['default'] == 1)) {
                        ob_end_clean();
                        echo json_encode([
                            'status' => 'warning',
                            'message' => 'Cannot delete the default record. Please set another record as default first.'
                        ]);
                        exit();
                    }
                } catch (Exception $e) {
                    // No default column
                }

                $result = $db->delete($table, [$id_column => $id]);

                if (!$result) {
                    throw new Exception('Failed to delete record from table: ' . $table . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Record deleted successfully',
                    'data' => ['id' => $id, 'table' => $table]
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Delete error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id, 'id_column' => $id_column],
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
                ]);
            }
            exit();
        }

        // Default response
        ob_end_clean();
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or unsupported action: ' . $action,
            'received_action' => $action
        ]);
        exit();
    }

    public function __construct() {
        // Database will be set when first accessed
        $this->db = null;
    }

    private function getDB() {
        if ($this->db === null) {
            global $db;
            $this->db = $db;
        }
        return $this->db;
    }

    /**
     * Replace placeholders in URL with actual values
     * Supports {column_name} placeholders from row data
     * Also supports $_GET and $_SESSION variables
     */
    private function replacePlaceholders($url, $row = []) {
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($row) {
            $key = $matches[1];
            // Try row data first
            if (isset($row[$key])) {
                return $row[$key];
            }
            // Try GET parameters
            if (isset($_GET[$key])) {
                return $_GET[$key];
            }
            // Try SESSION
            if (isset($_SESSION[$key])) {
                return $_SESSION[$key];
            }
            // Return original placeholder if not found
            return $matches[0];
        }, $url);
    }

    /**
     * Format row data using custom template
     * Supports {{column_name}} placeholders and PHP functions like {{number_format(balance, 2)}}
     */
    private function formatRowData($template, $row) {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($row) {
            $expression = trim($matches[1]);

            // Check if it's a simple column reference (just a column name, no functions/operators)
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expression)) {
                // It's a simple column name
                return isset($row[$expression]) ? $row[$expression] : '';
            }

            // It's an expression with function calls or operations
            // Replace column names with actual values in the expression
            // Sort by length descending to replace longer names first (avoid partial replacements)
            $columns = array_keys($row);
            usort($columns, function($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($columns as $key) {
                $val = $row[$key];
                // Replace column name with quoted value for strings, or raw value for numbers
                if ($val === null) {
                    $quotedVal = 'null';
                } elseif (is_numeric($val)) {
                    $quotedVal = $val;
                } else {
                    $quotedVal = "'" . addslashes($val) . "'";
                }
                $expression = preg_replace('/\b' . preg_quote($key, '/') . '\b/', $quotedVal, $expression);
            }

            // Evaluate the expression safely
            try {
                $result = @eval("return $expression;");
                return $result !== false && $result !== null ? $result : '';
            } catch (Throwable $e) {
                return ''; // Return empty if evaluation fails
            }
        }, $template);
    }

    public function table($name) {
        $this->table = $name;
        return $this;
    }

    public function title($title) {
        $this->title = $title;
        return $this;
    }

    public function list_url($url) {
        $this->list_url = (string) $url;
        return $this;
    }

    public function perPage($count) {
        $this->perPage = $count;
        return $this;
    }

    public function col($columns) {
        // Accept comma-separated string or array
        if (is_string($columns)) {
            $this->columns = array_map('trim', explode(',', $columns));
        } else {
            $this->columns = $columns;
        }
        return $this;
    }

    public function actions($actions) {
        // Accept array of action permissions
        // Example: ['status' => false, 'view' => true, 'edit' => true, 'delete' => false]
        if (is_array($actions)) {
            $this->actions = array_merge($this->actions, $actions);
        }
        return $this;
    }

    public function action_urls($urls) {
        // Accept array of custom action URLs
        // Example: ['add' => 'users/add', 'edit' => 'users/edit']
        if (is_array($urls)) {
            $this->action_urls = array_merge($this->action_urls, $urls);
        }
        return $this;
    }

    public function action_icons($icons) {
        // Override icons for built-in action buttons
        // Example: ['edit' => 'edit_note', 'delete' => 'delete_forever', 'view' => 'open_in_new']
        if (is_array($icons)) {
            $this->action_icons = array_merge($this->action_icons, $icons);
        }
        return $this;
    }

    public function id_column($column) {
        // Set which column to use for URLs (edit, view, delete)
        // Example: ->id_column('user_id')
        $this->id_column = $column;
        return $this;
    }

    public function where($conditions) {
        // Set where conditions for filtering
        // Example: ->where(['user_id' => '123'])
        // Example: ->where(['status' => 'active', 'type' => 'admin'])
        if (is_array($conditions)) {
            $this->where_conditions = array_merge($this->where_conditions, $conditions);
        }
        return $this;
    }

    public function order($column, $direction = 'ASC') {
        // Set order by conditions
        // Example: ->order('id', 'ASC')
        // Example: ->order('created_at', 'DESC')
        // Example: ->order(['id' => 'ASC', 'created_at' => 'DESC']) - for multiple columns
        if (is_array($column)) {
            $this->order_by = $column;
        } else {
            $this->order_by = [$column => strtoupper($direction)];
        }
        return $this;
    }

    public function col_width($column, $width) {
        // Set custom width for a specific column
        // Example: ->col_width('first_name', '150px')
        // Example: ->col_width('email', '200px')
        $this->col_widths[$column] = $width;
        return $this;
    }

    public function label($labels) {
        // Set custom labels for columns
        // Example: ->label(['first_name' => 'First Name', 'email' => 'Email Address'])
        if (is_array($labels)) {
            $this->col_labels = array_merge($this->col_labels, $labels);
        }
        return $this;
    }

    public function row($formats) {
        // Set custom formatting for row data
        // Example: ->row(['balance' => '{{currency}} {{number_format(balance, 2)}}'])
        // Supports {{column_name}} placeholders and PHP functions
        if (is_array($formats)) {
            $this->row_formats = array_merge($this->row_formats, $formats);
        }
        return $this;
    }

    public function relation($column, $relatedTable, $displayColumn, $foreignKey) {
        // Set relation for a column to fetch data from another table
        // Example: ->relation('country', 'countries', 'nicename', 'iso')
        // This means: The 'country' column in current table contains values from 'countries.iso',
        // and we want to display 'countries.nicename' instead
        $this->relations[$column] = [
            'table' => $relatedTable,
            'display' => $displayColumn,
            'foreign_key' => $foreignKey
        ];
        return $this;
    }

    public function custom_button($config) {
        // Add a custom button to the actions column
        // Example: ->custom_button([
        //     'label' => 'Approve',
        //     'icon' => 'check_circle',
        //     'url' => 'users/approve/{user_id}',
        //     'class' => 'text-green-600 hover:bg-green-100',
        //     'title' => 'Approve User',
        //     'confirm' => 'Are you sure you want to approve this user?', // Optional
        //     'onclick' => 'handleApprove(this)', // Optional custom JS function
        // ])
        if (is_array($config)) {
            $this->custom_buttons[] = $config;
        }
        return $this;
    }

    public function extra_fetch($columns) {
        // Fetch additional columns for placeholder use (e.g. in custom_button URLs)
        // without displaying them as table columns.
        // Example: ->extra_fetch('slug,owner_id')
        if (is_string($columns)) {
            $this->extra_fetch_cols = array_merge($this->extra_fetch_cols, array_map('trim', explode(',', $columns)));
        } elseif (is_array($columns)) {
            $this->extra_fetch_cols = array_merge($this->extra_fetch_cols, $columns);
        }
        return $this;
    }

    public function protect($ids) {
        // Set IDs that cannot be deleted (e.g., system roles, default records)
        // Example: ->protect([1, 2, 3, 4, 5])
        if (is_array($ids)) {
            $this->protected_records = $ids;
        } else {
            $this->protected_records = [$ids];
        }
        return $this;
    }

    public function render() {
        // Validate that table is set
        if (empty($this->table)) {
            throw new Exception('CRUD Error: Table name is not set. Use ->table("table_name") before ->render()');
        }

        // Check if table exists
        $db = $this->getDB();
            $tableCheck = $db->query("SHOW TABLES LIKE '{$this->table}'")->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($tableCheck)) {
            throw new Exception("CRUD Error: Table '{$this->table}' does not exist in the database. Please check your table name or create the table first.");
        }

        // Check if all CRUD actions are disabled (read-only list mode)
        $allActionsDisabled = !$this->actions['add'] && !$this->actions['edit'] &&
                              !$this->actions['view'] && !$this->actions['delete'];

        // Parse clean URLs
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $pathSegments = array_filter(explode('/', $path));

        // Get the last few segments for our CRUD operations
        $segments = array_slice($pathSegments, -3); // Get last 3 segments

        $action = '';
        $id = '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $search = '';

        // Only parse URL patterns if actions are enabled
        if (!$allActionsDisabled) {
            // Parse URL patterns
            if (count($segments) >= 2) {
                if ($segments[count($segments)-2] === 'edit' && is_numeric($segments[count($segments)-1])) {
                    $action = 'edit';
                    $id = $segments[count($segments)-1];
                } elseif ($segments[count($segments)-2] === 'view' && is_numeric($segments[count($segments)-1])) {
                    $action = 'view';
                    $id = $segments[count($segments)-1];
                } elseif ($segments[count($segments)-2] === 'search') {
                    $search = urldecode($segments[count($segments)-1]);
                }
            }

            if (count($segments) >= 1) {
                if ($segments[count($segments)-1] === 'create' || $segments[count($segments)-1] === 'add') {
                    $action = 'create';
                }
            }

            // Fallback to GET parameters if clean URL parsing fails
            if (!$action && !$search) {
                $action = $_GET['action'] ?? '';
                $id = $_GET['id'] ?? '';
                $search = $_GET['search'] ?? '';
            }
        }

        // Get search from query parameters (no redirect needed)
        if (isset($_GET['q']) && $_GET['q']) {
            $search = $_GET['q'];
        }

        // Normalize search input: trim whitespace and replace repeated whitespace with a single space
        if ($search !== '') {
            $search = trim(preg_replace('/\s+/', ' ', $search));
        }

        // Handle POST actions
        if ($_POST && isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    unset($_POST['action']);
                    $this->getDB()->insert($this->table, $_POST);
                    header("Location: ./");
                    exit;
                case 'update':
                    $id = $_POST['id'];
                    unset($_POST['action'], $_POST['id']);
                    $this->getDB()->update($this->table, $_POST, ['id' => $id]);
                    header("Location: ./");
                    exit;
                case 'toggle_status':
                    $id = $_POST['id'];
                    $current = $this->getDB()->get($this->table, 'status', [$this->id_column => $id]);
                    $currentStatus = $current['status'] ?? 0;
                    $newStatus = ($currentStatus == 1 || strtolower($currentStatus) == 'active') ? 0 : 1;
                    $this->getDB()->update($this->table, ['status' => $newStatus], [$this->id_column => $id]);
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                case 'delete':
                    $deleteId = $_POST['id'] ?? null;
                    if ($deleteId) {
                        $this->getDB()->delete($this->table, [$this->id_column => $deleteId]);
                    }
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                case 'bulk_delete':
                    $ids = explode(',', $_POST['ids'] ?? '');
                    $ids = array_filter($ids); // Remove empty values
                    if (!empty($ids)) {
                        $this->getDB()->delete($this->table, [$this->id_column => $ids]);
                    }
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
            }
        }

        // Get table structure with proper error handling
        try {
            $tableData = $this->getDB()->get($this->table, '*');
            if (!$tableData) {
                // If no data exists, get column names from table structure
                $tableStructure = $this->getDB()->query("DESCRIBE {$this->table}")->fetchAll(\PDO::FETCH_ASSOC);
                $allCols = array_column($tableStructure, 'Field');
            } else {
                $allCols = array_keys($tableData);
            }
        } catch (\PDOException $e) {
            // Check if it's a column error
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches);
                $invalidCol = $matches[1] ?? 'unknown';
                throw new Exception("CRUD Error: Column '{$invalidCol}' does not exist in table '{$this->table}'. Please check your ->col() configuration.");
            }
            throw new Exception("CRUD Error: Failed to fetch table structure for '{$this->table}'. " . $e->getMessage());
        }

        // Use specified columns or all columns
        $cols = !empty($this->columns) ? $this->columns : $allCols;

        // Validate that all specified columns actually exist in the table
        if (!empty($this->columns)) {
            $invalidCols = array_diff($this->columns, $allCols);
            if (!empty($invalidCols)) {
                throw new Exception("CRUD Error: Invalid column(s) in table '{$this->table}': " . implode(', ', $invalidCols) . ". Available columns: " . implode(', ', $allCols));
            }
        }

        // Ensure 'id' is always included for operations (but might not be displayed)
        if (!in_array('id', $cols) && in_array('id', $allCols)) {
            $displayCols = $cols;
            $cols = array_merge(['id'], $cols);
        } else {
            $displayCols = $cols;
        }

        // Also ensure id_column is included if it's different from 'id'
        if ($this->id_column !== 'id' && !in_array($this->id_column, $cols) && in_array($this->id_column, $allCols)) {
            $cols = array_merge($cols, [$this->id_column]);
        }

        // Also ensure 'status' column is fetched if it exists in the table (for toggle button)
        // but don't add it to displayCols unless explicitly requested
        if (!in_array('status', $cols) && in_array('status', $allCols)) {
            $cols = array_merge($cols, ['status']);
        }

        // Same for 'active' column
        if (!in_array('active', $cols) && in_array('active', $allCols)) {
            $cols = array_merge($cols, ['active']);
        }

        // Always fetch banned column if it exists in the table (for toggle button)
        if (!in_array('banned', $cols) && in_array('banned', $allCols)) {
            $cols = array_merge($cols, ['banned']);
        }

        // Always fetch featured column if it exists in the table (for toggle button)
        if (!in_array('featured', $cols) && in_array('featured', $allCols)) {
            $cols = array_merge($cols, ['featured']);
        }

        // Always fetch default column if it exists in the table (for toggle button)
        if (!in_array('default', $cols) && in_array('default', $allCols)) {
            $cols = array_merge($cols, ['default']);
        }

        // Fetch extra columns requested via ->extra_fetch() (available for placeholders, not displayed)
        foreach ($this->extra_fetch_cols as $extraCol) {
            if (!in_array($extraCol, $cols) && in_array($extraCol, $allCols)) {
                $cols = array_merge($cols, [$extraCol]);
            }
        }

        // If all actions are disabled, skip form logic and always show list
        if ($allActionsDisabled) {
            return $this->list($cols, $displayCols, $page, $search);
        }

        if ($action == 'create' || $action == 'edit' || $action == 'view') {
            $data = ($action == 'edit' || $action == 'view') ? $this->getDB()->get($this->table, '*', ['id' => $id]) : [];
            return $this->form($allCols, $data, $action);
        }

        return $this->list($cols, $displayCols, $page, $search);
    }

    private function list($cols, $displayCols, $page, $search) {
        $offset = ($page - 1) * $this->perPage;

        // Check if we have any actions enabled
        $hasAnyAction = $this->actions['status'] || $this->actions['view'] ||
                        $this->actions['edit'] || $this->actions['delete'];

        $bulkDeleteEnabled = !empty($this->actions['bulk_delete']);

        // Get search column from query parameter
        $searchColumn = $_GET['search_col'] ?? 'all';

        // Build where clause for search
        $where = [];

        // Add pre-defined where conditions first
        if (!empty($this->where_conditions)) {
            $where = array_merge($where, $this->where_conditions);
        }

        if ($search) {
            // Split search term into words
            $searchWords = array_filter(explode(' ', $search));
            $searchCols = [];

            if ($searchColumn === 'all') {
                // Search in all columns - match ANY word
                $exactMatchColumns = ['payment_status', 'booking_status', 'status', 'module_type', 'module'];
                $knownStatusValues = ['paid', 'unpaid', 'confirmed', 'pending', 'cancelled', 'failed', 'stays', 'flights', 'tours', 'hotels'];
                
                    $allCurrencies = [];
                
                    if (isset($_SESSION['app_currency'])) {
                        try {
                            $allCurrencies = $this->db->select('currencies', ['name', 'rate', 'default'], ['status' => '1']);
                            foreach($allCurrencies as $cur) {
                                if (($cur['default'] ?? 0) == 1) {
                                    $defaultRate = (float)($cur['rate'] ?? 1.0);
                                    break;
                                }
                            }
                        } catch (Exception $e) {}
                    }

                    foreach ($searchWords as $word) {
                        $isKnownStatus = in_array(strtolower($word), $knownStatusValues, true);
                        $cleanedWord = str_replace(',', '', $word);
                        $isNumeric = is_numeric($cleanedWord);
                        
                        foreach ($cols as $col) {
                            if ($col !== 'id') {
                                if ($isKnownStatus && in_array($col, $exactMatchColumns, true)) {
                                    if (!isset($searchCols[$col])) { $searchCols[$col] = []; }
                                    if (is_array($searchCols[$col])) { $searchCols[$col][] = $word; }
                                } elseif ($col === 'first_name') {
                                    // For user search, check first_name, last_name, and email
                                    $searchCols['first_name[~]'][] = $word;
                                    $searchCols['last_name[~]'][] = $word;
                                    $searchCols['email[~]'][] = $word;
                                } elseif ($isNumeric && (strpos(strtolower($col), 'price') !== false || strpos(strtolower($col), 'markup') !== false || strpos(strtolower($col), 'commission') !== false || strpos(strtolower($col), 'amount') !== false)) {
                                    // For price/numeric columns, handle comma-formatted numbers and currency conversion
                                    $searchCols[$col . '[~]'][] = $cleanedWord;
                                    $val = floatval($cleanedWord);
                                    
                                    if (!isset($searchCols[$col])) { $searchCols[$col] = []; }
                                    if (is_array($searchCols[$col])) { $searchCols[$col][] = $val; }

                                    // Currency Support
                                    if (!empty($allCurrencies)) {
                                        $sessionCurrency = $_SESSION['app_currency'] ?? 'USD';
                                
                                        foreach($allCurrencies as $cur) {
                                            if ($cur['name'] === $sessionCurrency) { $sessionRate = $cur['rate']; break; }
                                        }
                                        
                                        if ($sessionRate > 0) {
                                            foreach($allCurrencies as $cur) {
                                                // Calculate what the base price would be if this was the base currency
                                                // Using default currency as the pivot
                                                $possibleBasePrice = round(($val / $sessionRate) * $cur['rate'], 2);
                                                if (is_array($searchCols[$col])) { $searchCols[$col][] = $possibleBasePrice; }
                                            }
                                        }
                                    }
                                } else {
                                    $searchCols[$col . '[~]'][] = $word;
                                }
                            }
                        }
                    }
                    
                    // Sanitize searchCols - remove duplicates and flatten single items
                    foreach ($searchCols as $k => $v) {
                        if (is_array($v)) {
                            $v = array_unique($v);
                            $searchCols[$k] = (count($v) === 1) ? reset($v) : array_values($v);
                        }
                    }
            } else {
                // Search in specific column only - match ANY word
                if (in_array($searchColumn, $cols)) {
                    $exactMatchColumns = ['payment_status', 'booking_status', 'status', 'module_type', 'module', 'price_markup', 'price', 'commission', 'amount', 'total_price'];
                    $allCurrencies = [];
                    
                    if (isset($_SESSION['app_currency'])) {
                        try {
                            $allCurrencies = $this->db->select('currencies', ['name', 'rate', 'default'], ['status' => '1']);
                            foreach($allCurrencies as $cur) {
                                if (($cur['default'] ?? 0) == 1) {
                                    $defaultRate = (float)($cur['rate'] ?? 1.0);
                                    break;
                                }
                            }
                        } catch (Exception $e) {}
                    }

                    foreach ($searchWords as $word) {
                        if ($searchColumn === 'first_name') {
                            $searchCols['first_name[~]'][] = $word;
                            $searchCols['last_name[~]'][] = $word;
                            $searchCols['email[~]'][] = $word;
                            continue;
                        }
                        
                        $isNumericCol = (strpos(strtolower($searchColumn), 'price') !== false || 
                                        strpos(strtolower($searchColumn), 'markup') !== false || 
                                        strpos(strtolower($searchColumn), 'commission') !== false || 
                                        strpos(strtolower($searchColumn), 'amount') !== false);
                        
                        $useExactMatch = in_array($searchColumn, $exactMatchColumns, true) && count($searchWords) === 1;
                        
                        if ($isNumericCol) {
                            // Check for formatted price (with commas, decimals, or both)
                            $cleanedWord = str_replace(',', '', $word);
                            if (is_numeric($cleanedWord)) {
                                $val = floatval($cleanedWord);
                                if (!isset($searchCols[$searchColumn])) { $searchCols[$searchColumn] = []; }
                                $searchCols[$searchColumn][] = $val;
                                // Also allow partial match for numeric columns when searched specifically
                                $searchCols[$searchColumn . '[~]'][] = $cleanedWord;

                                // Currency Support
                                if (!empty($allCurrencies)) {
                                    $sessionCurrency = $_SESSION['app_currency'] ?? 'USD';
                                
                                    foreach($allCurrencies as $cur) {
                                        if ($cur['name'] === $sessionCurrency) { $sessionRate = $cur['rate']; break; }
                                    }
                                    
                                    if ($sessionRate > 0) {
                                        foreach($allCurrencies as $cur) {
                                            $possibleBasePrice = round(($val / $sessionRate) * $cur['rate'], 2);
                                            $searchCols[$searchColumn][] = $possibleBasePrice;
                                        }
                                    }
                                }
                                continue;
                            }
                        }
                        
                        if ($useExactMatch) {
                            $searchCols[$searchColumn][] = $word;
                        } else {
                            $searchCols[$searchColumn . '[~]'][] = $word;
                        }
                    }
                    
                    // Sanitize searchCols - remove duplicates and flatten single items
                    foreach ($searchCols as $k => $v) {
                        if (is_array($v)) {
                            $v = array_unique($v);
                            $searchCols[$k] = (count($v) === 1) ? reset($v) : array_values($v);
                        }
                    }
                }
            }
            if ($searchCols) {
                $where['OR'] = $searchCols;
            }
        }

        // Get total count
        $total = $this->getDB()->count($this->table, $where);
        $totalPages = ceil($total / $this->perPage);

        // Get paginated data - select the columns we need
        // Use custom order if provided, otherwise default to id DESC
        $where['ORDER'] = !empty($this->order_by) ? $this->order_by : ['id' => 'DESC'];
        $where['LIMIT'] = [$offset, $this->perPage];

        // Select columns. render() already prepends 'id' to $cols whenever the table
        // actually has an 'id' column, so don't force a non-existent 'id' here (that
        // breaks tables whose primary key is not named 'id', e.g. generic DB browsing).
        $selectCols = $cols;

        // Extract columns used in row formats and add them to selectCols if not already present
        foreach ($this->row_formats as $colName => $template) {
            if (!is_string($template)) {
                continue;
            }
            // Find all {{column_name}} patterns in the template
            preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
            foreach ($matches[1] as $expression) {
                // Remove quoted strings to avoid matching classes like 'bg-red-500' as columns
                $cleanExpression = preg_replace('/(\'|")(?:\1|\\.|[^\1])*?\1/', '', $expression);
                
                // Extract simple column names from CLEANED expression
                preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $cleanExpression, $colMatches);
                foreach ($colMatches[1] as $col) {
                    // Skip PHP function names, add only if it looks like a column and not already in selectCols
                    if (!in_array($col, $selectCols) && !in_array($col, ['number_format', 'date', 'strtotime', 'ucfirst', 'strtolower', 'strtoupper', 'trim', 'substr', 'strlen']) && !function_exists($col)) {
                        $selectCols[] = $col;
                    }
                }
            }
        }

        // Fetch data with proper error handling
        try {
            // Debug logging
            if (in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1'])) {
            }
            
            $data = $this->getDB()->select($this->table, $selectCols, $where);
        } catch (\PDOException $e) {
            // Check if it's a column error
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches);
                $invalidCol = $matches[1] ?? 'unknown';

                // Get available columns for helpful error message
                $availableCols = [];
                try {
                    $tableStructure = $this->getDB()->query("DESCRIBE {$this->table}")->fetchAll(\PDO::FETCH_ASSOC);
                    $availableCols = array_column($tableStructure, 'Field');
                } catch (\Exception $ex) {
                    // Ignore if we can't get column list
                }

                $errorMsg = "CRUD Error: Column '{$invalidCol}' does not exist in table '{$this->table}'. ";
                $errorMsg .= "Check your ->col() configuration. ";
                if (!empty($availableCols)) {
                    $errorMsg .= "Available columns: " . implode(', ', $availableCols);
                }
                throw new Exception($errorMsg);
            }
            throw new Exception("CRUD Error: Failed to fetch data from table '{$this->table}'. " . $e->getMessage());
        }

        $html = '
        <div class="cr">
            <!-- Header with title and actions -->
            <div class="cr-head">
                <div class="cr-head-row">';

        if (trim((string) $this->title) !== '') {
            $html .= '
                    <div>
                        <h2 class="cr-title">' . htmlspecialchars($this->title) . '</h2>
                        <p class="cr-meta">' . number_format($total) . ' records</p>
                    </div>';
        }

        $html .= '
                    <div class="cr-tools">
                        <!-- Column Selection Dropdown -->
                        <div class="cr-cols">
                            <button type="button" id="columnToggleBtn"
                                    class="cr-btn cr-btn-ghost">
                                <span class="flex items-center gap-2">
                                    <span class="material-symbols-outlined">view_column</span>
                                    View Columns
                                </span>
                             </button>
                            <div id="columnDropdown" class="hidden cr-pop">
                                <div class="p-3">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="cr-pop-title">Show Columns</h3>
                                        <button type="button" onclick="selectAllColumns()" class="cr-link">Select All</button>
                                    </div>
                                    <div class="space-y-2" id="columnCheckboxes">';

        // Add checkboxes for each column in displayCols
        foreach ($displayCols as $col) {
            if ($col === 'id') continue; // Skip ID column as it will be replaced with serial
            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $html .= '
                                        <label class="cr-check">
                                            <input type="checkbox" checked class="column-toggle"
                                                   data-column="' . $col . '">
                                            <span>' . htmlspecialchars($label) . '</span>
                                        </label>';
        }

        $html .= '
                                    </div>
                                </div>
                            </div>
                        </div>';

        // Search (only if enabled)
        if (isset($this->actions['search']) && $this->actions['search']) {
            $searchAction = $this->list_url !== '' ? htmlspecialchars($this->list_url, ENT_QUOTES, 'UTF-8') : '';
            $html .= '
                        <form method="GET" action="' . $searchAction . '" class="cr-search">
                            <select name="search_col" class="cr-field cr-select" aria-label="Search in">
                                <option value="all"' . ($searchColumn === 'all' ? ' selected' : '') . '>All Columns</option>';

            // Add option for each column
            foreach ($cols as $col) {
                if ($col !== 'id') {
                    $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
                    $selected = ($searchColumn === $col) ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($col) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
                }
            }

            $html .= '
                            </select>
                            <input type="text" name="q" value="' . htmlspecialchars($search) . '"
                                   placeholder="Search records..."
                                   class="cr-field" aria-label="Search records">
                            <button type="submit" class="cr-btn cr-btn-square" aria-label="Search">
                                <span class="material-symbols-outlined">search</span>
                            </button>';

            if ($search) {
                $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $clearHref = $this->list_url !== '' ? $this->list_url : $currentPath;
                $html .= '<a href="' . htmlspecialchars($clearHref, ENT_QUOTES, 'UTF-8') . '" class="cr-btn cr-btn-ghost cr-btn-square" aria-label="Clear search">
                            <span class="material-symbols-outlined">close</span>

                          </a>';
            }

            $html .= '
                        </form>';
        }

        $html .= '';

        // Add button (if enabled)
        if (isset($this->actions['add']) && $this->actions['add']) {
            $addUrl = $this->action_urls['add'] ?? strtolower($this->table) . '/add';
            // Replace placeholders like {user_id} with values from GET/SESSION
            $addUrl = $this->replacePlaceholders($addUrl);
            $html .= '
                        <a href="' . root . $addUrl . '" class="cr-btn cr-btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            ' . T::create_new . '
                        </a>';
        }

        if ($bulkDeleteEnabled) {
            $html .= '
                        <div id="bulkActions" class="hidden">
                            <button type="button" onclick="bulkDelete()" class="cr-btn cr-btn-danger">
                                <span class="material-symbols-outlined">delete</span>
                                Delete Selected
                            </button>
                        </div>';
        }

        $html .= '
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="cr-scroll" id="tableContainer">
                <table class="cr-table">
                    <thead>
                        <tr>';

        if ($bulkDeleteEnabled) {
            $html .= '
                            <th id="selectAllHeader" class="cr-c-check checkbox-cell">
                                <input type="checkbox" id="selectAll" aria-label="Select all rows"
                                       onchange="toggleAllRows(this)">
                            </th>';
        }

        $html .= '
                            <th class="cr-c-num">#</th>';

        // Add Status column header after serial number if status action is enabled
        if (isset($this->actions['status']) && $this->actions['status']) {
            $html .= '
                            <th class="cr-c-switch">Status</th>';
        }

        // Add Banned column header after status if banned action is enabled
        // Always show Banned column header if action enabled and banned column exists in table
        $allCols = array_keys($this->getDB()->get($this->table, '*') ?: []);
        if (isset($this->actions['banned']) && $this->actions['banned'] && in_array('banned', $allCols)) {
            $html .= '
                            <th class="cr-c-switch">Banned</th>';
        }

        // Add Featured column header if featured action is enabled and featured column exists in table
        if (isset($this->actions['featured']) && $this->actions['featured'] && in_array('featured', $allCols)) {
            $html .= '
                            <th class="cr-c-switch">Featured</th>';
        }

        // Add Default column header if default action is enabled and default column exists in table
        if (isset($this->actions['default']) && $this->actions['default'] && in_array('default', $allCols)) {
            $html .= '
                            <th class="cr-c-switch">Default</th>';
        }

        $html .= '';

        foreach ($displayCols as $col) {
            if ($col === 'id') continue; // Skip ID column
            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $fixedClass = '';

            // Apply custom width if set
            $widthStyle = isset($this->col_widths[$col]) ? 'style="width: ' . $this->col_widths[$col] . '; min-width: ' . $this->col_widths[$col] . ';"' : '';

            $html .= '<th class="column-header" data-column="' . $col . '" ' . $widthStyle . '>' . htmlspecialchars($label) . '</th>';
        }

        // Only show Actions header if any action is enabled
        $actionColW = 160; // default fallback
        if ($hasAnyAction) {
            // Compute action column width dynamically based on number of buttons
            $actionBtnCount  = count($this->custom_buttons);
            if (!empty($this->actions['view']))   $actionBtnCount++;
            if (!empty($this->actions['edit']))   $actionBtnCount++;
            if (!empty($this->actions['delete'])) $actionBtnCount++;
            $actionColW = max(120, $actionBtnCount * 38 + max(0, $actionBtnCount - 1) * 4 + 32);

            $html .= '
                            <th id="actionsHeader" class="cr-c-actions actions-cell" style="width:' . $actionColW . 'px;min-width:' . $actionColW . 'px;">Actions</th>'; 
        }

        $html .= '
                        </tr>
                    </thead>
                    <tbody id="tableBody">';

        $serialNumber = ($page - 1) * $this->perPage + 1; // Calculate starting serial number
        foreach ($data as $row) {
            $html .= '<tr class="row-item" data-id="' . $row[$this->id_column] . '">';

                 // Checkbox column (fixed on left side)
                 if ($bulkDeleteEnabled) {
                  $html .= '<td class="cr-c-check checkbox-cell">
                        <input type="checkbox" class="row-checkbox" aria-label="Select row"
                            value="' . $row[$this->id_column] . '" onchange="toggleRowSelection(this)">
                         </td>';
                 }

            // Serial Number
            $html .= '<td class="cr-c-num">' . $serialNumber . '</td>';

            // Status Toggle Switch (if enabled and column exists) - Smaller version
            if (isset($this->actions['status']) && $this->actions['status'] && (isset($row['status']) || isset($row['active']))) {
                $statusCol = isset($row['status']) ? 'status' : 'active';
                $isActive = ($row[$statusCol] == 1 || strtolower($row[$statusCol]) == 'active');
                $html .= '<td class="cr-c-switch status-cell">
                            <label class="cr-switch">
                                <input type="checkbox" class="toggle-status-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       data-column="' . $statusCol . '"
                                       ' . ($isActive ? 'checked' : '') . '>
                                <span class="cr-track"></span>
                            </label>
                          </td>';
            }

            // Banned Toggle Switch (if enabled and column exists) - Green color
            // Always show banned switch if action enabled and banned column exists in table
            if (isset($this->actions['banned']) && $this->actions['banned'] && array_key_exists('banned', $row)) {
                $isBanned = ($row['banned'] == 1);
                $html .= '<td class="cr-c-switch banned-cell">
                            <label class="cr-switch">
                                <input type="checkbox" class="toggle-status-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       data-column="banned"
                                       ' . ($isBanned ? 'checked' : '') . '>
                                <span class="cr-track is-danger"></span>
                            </label>
                          </td>';
            }

            // Featured Toggle Switch (if enabled and column exists); saved through toggle_status, column "featured"
            if (isset($this->actions['featured']) && $this->actions['featured'] && array_key_exists('featured', $row)) {
                // only an enabled row can be featured: the switch is locked while the row is disabled
                $rowEnabled = !isset($row['status']) || $row['status'] == 1;
                $isFeatured = ($row['featured'] == 1) && $rowEnabled;
                $html .= '<td class="cr-c-switch featured-cell">
                            <label class="cr-switch"' . ($rowEnabled ? '' : ' title="Enable it first to feature it"') . '>
                                <input type="checkbox" class="toggle-status-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       data-column="featured"
                                       ' . ($isFeatured ? 'checked' : '') . ($rowEnabled ? '' : ' disabled') . '>
                                <span class="cr-track"></span>
                            </label>
                          </td>';
            }

            // Default Toggle Switch (if enabled and column exists) - Orange color
            if (isset($this->actions['default']) && $this->actions['default'] && array_key_exists('default', $row)) {
                $isDefault = ($row['default'] == 1);
                $html .= '<td class="cr-c-switch default-cell">
                            <label class="cr-switch">
                                <input type="checkbox" class="toggle-default-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       ' . ($isDefault ? 'checked' : '') . '>
                                <span class="cr-track"></span>
                            </label>
                          </td>';
            }

            foreach ($displayCols as $col) {
                if ($col === 'id') continue; // Skip ID column
                $value = $row[$col] ?? '';

                // Check if this column has a custom row format
                if (isset($this->row_formats[$col])) {
                    $rowFormat = $this->row_formats[$col];
                    // Special handling for image columns (img, room_images, hotel_images, etc.) - extract URL from JSON
                    if (($col === 'img' || strpos($col, '_images') !== false || strpos($col, '_image') !== false) && !empty($value) && is_string($value)) {
                        $images = json_decode($value, true);
                        if (is_array($images) && !empty($images)) {
                            // Find default image or use first image
                            $imageUrl = '';
                            foreach ($images as $img) {
                                if (isset($img['default']) && $img['default']) {
                                    $imageUrl = $img['url'];
                                    break;
                                }
                            }
                            // If no default found, use first image
                            if (empty($imageUrl) && isset($images[0]['url'])) {
                                $imageUrl = $images[0]['url'];
                            }
                            // Replace the column value with the extracted URL
                            $row[$col] = $imageUrl;
                        }
                    }

                    // Special handling for room_options - extract minimum price from JSON
                    if ($col === 'room_options' && !empty($value) && is_string($value)) {
                        $options = json_decode($value, true);
                        if (is_array($options) && !empty($options)) {
                            $minPrice = null;
                            foreach ($options as $option) {
                                if (isset($option['price'])) {
                                    $price = floatval($option['price']);
                                    if ($minPrice === null || $price < $minPrice) {
                                        $minPrice = $price;
                                    }
                                }
                            }

                            $countOptions = count($options); // get number of options

                            // Append count to the min price
                            $row[$col] = ($minPrice !== null ? number_format($minPrice, 2) : '0.00') . ",  Total Options ({$countOptions})";
                            // Example output: "45.00 (3)" -> min price 45, 3 options
                        } else {
                            $row[$col] = '0.00 (0)';
                        }
                    }

                    if (is_callable($rowFormat)) {
                        try {
                            $value = call_user_func($rowFormat, $row);
                        } catch (Throwable $e) {
                            $value = '';
                            error_log('CRUD Row Format Error for column ' . $col . ': ' . $e->getMessage());
                        }
                    } elseif (is_string($rowFormat)) {
                        $value = $this->formatRowData($rowFormat, $row);
                    }
                }
                // Check if this column has a relation defined
                elseif (isset($this->relations[$col])) {
                    $relation = $this->relations[$col];
                    $relatedTable = $relation['table'];
                    $displayColumn = $relation['display'];
                    $foreignKey = $relation['foreign_key'];

                    // Check if value is null or empty before attempting relation lookup
                    if (empty($value) || $value === '0' || $value === 0) {
                        $value = '<span class="cr-none">—</span>';
                    } else {
                        // Fetch related data with error handling
                        try {
                            $relatedData = $this->getDB()->get($relatedTable, $displayColumn, [$foreignKey => $value]);

                            if ($relatedData) {
                                // When selecting a single column, Medoo returns the value directly (not an array)
                                $value = $relatedData;
                            } else {
                                // Show a simple placeholder instead of error
                                $value = '<span class="cr-none">—</span>';
                            }
                        } catch (Exception $e) {
                            // Show a simple placeholder instead of error
                            $value = '<span class="cr-none">—</span>';
                            error_log('Relation Error: ' . $e->getMessage() . ' | Table: ' . $relatedTable . ' | Display: ' . $displayColumn . ' | Foreign Key: ' . $foreignKey . ' | Value: ' . $row[$col]);
                        }
                    }
                }

                // Don't truncate if this column has a custom row format (it might contain HTML)
                if (!isset($this->row_formats[$col]) && strlen($value) > 50) {
                    $value = substr($value, 0, 50) . '...';
                }

                $fixedClass = '';

                // Apply custom width if set
                $widthStyle = isset($this->col_widths[$col]) ? 'style="width: ' . $this->col_widths[$col] . '; min-width: ' . $this->col_widths[$col] . ';"' : '';

                // Special handling for status/active columns
                if (($col === 'status' || $col === 'active') && !isset($this->row_formats[$col])) {
                    $statusClass = ($value == 1 || strtolower($value) == 'active') ? 'is-on' : 'is-off';
                    $statusText = ($value == 1 || strtolower($value) == 'active') ? 'Active' : 'Inactive';
                    $html .= '<td class="column-cell" data-column="' . $col . '" ' . $widthStyle . '>
                                <span class="cr-status ' . $statusClass . '"><i></i>' . $statusText . '</span>
                              </td>';
                } else {
                    $html .= '<td class="column-cell" data-column="' . $col . '" ' . $widthStyle . '>' . $value . '</td>';
                }
            }

            // Only show actions cell if any action is enabled
            if ($hasAnyAction) {
                $html .= '
                        <td class="cr-c-actions actions-cell" style="width:' . $actionColW . 'px;min-width:' . $actionColW . 'px;">
                            <div class="cr-acts">';

                // View Button
                if (isset($this->actions['view']) && $this->actions['view']) {
                    $viewUrl = $this->action_urls['view'] ?? 'view';
                    // Replace placeholders like {user_id} with actual values
                    $viewUrl = $this->replacePlaceholders($viewUrl, $row);
                    $html .= '
                                <a href="' . $viewUrl . '/"
                                   class="cr-act"
                                   title="View">
                                    <span class="material-symbols-outlined">' . ($this->action_icons['view'] ?? 'visibility') . '</span>
                                </a>';
                }

                // Custom Buttons (position: before_edit)
                foreach ($this->custom_buttons as $button) {
                    if (($button['position'] ?? '') !== 'before_edit') continue;
                    $btnUrl = isset($button['url']) ? $this->replacePlaceholders($button['url'], $row) : '#';
                    $btnLabel = $button['label'] ?? '';
                    $btnIcon = trim((string) ($button['icon'] ?? ''));
                    $btnClass = $button['class'] ?? '';
                    $btnTitle = $button['title'] ?? $btnLabel;
                    $btn_target = $button['target'] ?? '_self';
                    $btnOnclick = '';
                    if (isset($button['onclick'])) {
                        $btnOnclick = 'onclick="' . htmlspecialchars($this->replacePlaceholders($button['onclick'], $row)) . '"';
                    } elseif (isset($button['confirm'])) {
                        $btnOnclick = 'onclick="return confirm(\'' . addslashes($this->replacePlaceholders($button['confirm'], $row)) . '\')"';
                    }
                    $html .= '
                        <a href="' . $btnUrl . '"
                        class="cr-act' . (strlen($btnLabel) > 0 ? ' has-label' : '') . (stripos($btnClass, 'red') !== false ? ' is-danger' : '') . ' ' . $btnClass . '"
                        title="' . htmlspecialchars($btnTitle) . '" target="' . $btn_target . '"
                        ' . $btnOnclick . '>
                            ' . (!empty($btnIcon) ? '<span class="material-symbols-outlined">' . htmlspecialchars($btnIcon) . '</span>' : '') . '
                            ' . (strlen($btnLabel) > 0 ? '<span>' . htmlspecialchars($btnLabel) . '</span>' : '') . '
                        </a>';
                }

                // Edit Button
                if (isset($this->actions['edit']) && $this->actions['edit']) {
                    $editUrl = $this->action_urls['edit'] ?? 'edit';
                    // Replace placeholders like {user_id} with actual values
                    $editUrl = $this->replacePlaceholders($editUrl, $row);
                    $html .= '
                                <a href="' . $editUrl . '"
                                   class="cr-act"
                                   title="Edit">
                                    <span class="material-symbols-outlined">' . ($this->action_icons['edit'] ?? 'edit') . '</span>
                                </a>';
                }

                // Delete Button (AJAX)
                if (isset($this->actions['delete']) && $this->actions['delete']) {
                    $idValue = $row[$this->id_column];
                    $isProtected = in_array($idValue, $this->protected_records);

                    if ($isProtected) {
                        // Protected record - show disabled button
                        $html .= '
                                <button type="button"
                                        class="cr-act"
                                        title="Cannot delete system record"
                                        disabled>
                                    <span class="material-symbols-outlined">' . ($this->action_icons['delete'] ?? 'delete') . '</span>
                                </button>';
                    } else {
                        // Normal deletable record
                        $html .= '
                                <button type="button"
                                        class="delete-btn cr-act is-danger"
                                        data-table="' . $this->table . '"
                                        data-id="' . $idValue . '"
                                        title="Delete">
                                    <span class="material-symbols-outlined">' . ($this->action_icons['delete'] ?? 'delete') . '</span>
                                </button>';
                    }
                }

                // Custom Buttons (default: after delete)
                foreach ($this->custom_buttons as $button) {
                    if (($button['position'] ?? '') === 'before_edit') continue;
                    $btnUrl = isset($button['url']) ? $this->replacePlaceholders($button['url'], $row) : '#';
                    $btnLabel = $button['label'] ?? '';
                    $btnIcon = trim((string) ($button['icon'] ?? ''));
                    $btnClass = $button['class'] ?? '';
                    $btnTitle = $button['title'] ?? $btnLabel;
                    $btn_target = $button['target'] ?? '_self';

                    // Replace placeholders in onclick attribute if it exists
                    $btnOnclick = '';
                    if (isset($button['onclick'])) {
                        $onclickWithPlaceholders = $this->replacePlaceholders($button['onclick'], $row);
                        $btnOnclick = 'onclick="' . htmlspecialchars($onclickWithPlaceholders) . '"';
                    } elseif (isset($button['confirm'])) {
                        $confirmMessage = $this->replacePlaceholders($button['confirm'], $row);
                        $btnOnclick = 'onclick="return confirm(\'' . addslashes($confirmMessage) . '\')"';
                    }

                    $html .= '
                        <a href="' . $btnUrl . '"
                        class="cr-act' . (strlen($btnLabel) > 0 ? ' has-label' : '') . (stripos($btnClass, 'red') !== false ? ' is-danger' : '') . ' ' . $btnClass . '"
                        title="' . htmlspecialchars($btnTitle) . '" target="'.$btn_target.'"
                        ' . $btnOnclick . '>
                            ' . (!empty($btnIcon) ? '<span class="material-symbols-outlined">' . htmlspecialchars($btnIcon) . '</span>' : '') . '
                            ' . (strlen($btnLabel) > 0 ? '<span>' . htmlspecialchars($btnLabel) . '</span>' : '') . '
                        </a>';
                }

                $html .= '
                            </div>
                        </td>';
            }

            $html .= '
                    </tr>';
            $serialNumber++; // Increment serial number
        }

        $html .= '
                    </tbody>
                </table>
            </div>';

        // No content message
        if (empty($data)) {
            $html .= '
            <div class="cr-empty">
                <div class="cr-empty-icon">
                    <span class="material-symbols-outlined">inbox</span>
                </div>
                <h3>' . T::no_content_available . '</h3>
                <p>' . T::no_records_found_in_this_table . '</p>';

            // Show add button if enabled
            if (isset($this->actions['add']) && $this->actions['add']) {
                $addUrl = $this->action_urls['add'] ?? strtolower($this->table) . '/add';
                $addUrl = $this->replacePlaceholders($addUrl);
                $html .= '
                <a href="' . root . $addUrl . '" class="cr-btn cr-btn-primary">
                    <span class="material-symbols-outlined">add</span>
                    ' . T::create_new . '
                </a>';
            }

            $html .= '
            </div>';
        }

        // Pagination
        if ($totalPages > 1) {
            // Get current path without query parameters for pagination
            $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            $html .= '
            <div class="cr-foot">
                <div class="cr-foot-row">
                    <div class="cr-meta">
                        Showing ' . (($page - 1) * $this->perPage + 1) . ' to ' . min($page * $this->perPage, $total) . ' of ' . number_format($total) . ' results
                    </div>
                    <div>
                        <nav class="cr-pages pagination-nav" aria-label="Pagination">';

            // Previous button
            if ($page > 1) {
                $prevPage = $page - 1;
                $params = ['page' => $prevPage];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link cr-page is-wide">Previous</a>';
            }

            // Page numbers
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);

            if ($start > 1) {
                $params = ['page' => 1];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link cr-page">1</a>';
                if ($start > 2) $html .= '<span class="cr-gap">…</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $params = ['page' => $i];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $active = $i == $page ? 'is-current" aria-current="page' : '';
                $html .= '<a href="' . $url . '" class="pagination-link cr-page ' . $active . '">' . $i . '</a>';
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) $html .= '<span class="cr-gap">…</span>';
                $params = ['page' => $totalPages];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link cr-page">' . $totalPages . '</a>';
            }

            // Next button
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $params = ['page' => $nextPage];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link cr-page is-wide">Next</a>';
            }

            $html .= '
                        </nav>
                    </div>
                </div>
            </div>';
        }

        $html .= '</div>';

        // Add JavaScript for hash preservation in pagination
        if ($totalPages > 1) {
            $html .= '
        <script>
        // Function to update pagination links with current hash
        function updatePaginationHash() {
            const hash = window.location.hash;
            document.querySelectorAll(".pagination-link").forEach(function(link) {
                // Remove any existing hash from the link
                const urlWithoutHash = link.href.split("#")[0];
                // Add current hash if it exists
                link.href = hash ? urlWithoutHash + hash : urlWithoutHash;
            });
        }

        // Update on page load
        document.addEventListener("DOMContentLoaded", updatePaginationHash);

        // Update whenever hash changes (when clicking tabs)
        window.addEventListener("hashchange", updatePaginationHash);

        // Also update immediately if DOM is already loaded
        if (document.readyState === "complete" || document.readyState === "interactive") {
            updatePaginationHash();
        }
        </script>';
        }

        // Add JavaScript for multi-select functionality
        $html .= '
        <script>
        let selectedRows = new Set();

        function toggleAllRows(checkbox) {
            const checkboxes = document.querySelectorAll(".row-checkbox");
            const bulkActions = document.getElementById("bulkActions");

            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedRows.add(cb.value);
                    cb.closest("tr").classList.add("is-selected");
                } else {
                    selectedRows.delete(cb.value);
                    cb.closest("tr").classList.remove("is-selected");
                }
            });

            if (bulkActions) {
                bulkActions.classList.toggle("hidden", selectedRows.size === 0);
            }
        }

        function toggleRowSelection(checkbox) {
            const bulkActions = document.getElementById("bulkActions");
            const selectAll = document.getElementById("selectAll");

            if (checkbox.checked) {
                selectedRows.add(checkbox.value);
                checkbox.closest("tr").classList.add("is-selected");
            } else {
                selectedRows.delete(checkbox.value);
                checkbox.closest("tr").classList.remove("is-selected");
                if (selectAll) {
                    selectAll.checked = false;
                }
            }

            if (bulkActions) {
                bulkActions.classList.toggle("hidden", selectedRows.size === 0);
            }

            // Update "select all" checkbox state
            const totalCheckboxes = document.querySelectorAll(".row-checkbox").length;
            if (selectAll) {
                selectAll.checked = selectedRows.size === totalCheckboxes;
            }
        }

        function bulkDelete() {
            if (selectedRows.size === 0) return;

            if (confirm(`Are you sure you want to delete ${selectedRows.size} selected records?`)) {
                const table = "' . $this->table . '";
                const ids = Array.from(selectedRows);
                let successCount = 0;
                let failCount = 0;

                // Delete each record via AJAX
                const deletePromises = ids.map(id => {
                    return fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "delete_record",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            successCount++;
                            // Remove the row
                            const row = document.querySelector(`tr[data-id="${id}"]`);
                            if (row) {
                                row.style.transition = "all 0.3s ease-out";
                                row.style.opacity = "0";
                                setTimeout(() => row.remove(), 300);
                            }
                        } else {
                            failCount++;
                        }
                        return data;
                    })
                    .catch(error => {
                        console.error("Error deleting ID:", id, error);
                        failCount++;
                    });
                });

                // Wait for all deletions to complete
                Promise.all(deletePromises).then(() => {
                    selectedRows.clear();
                    const selectAllCheckbox = document.querySelector("thead input[type=\'checkbox\']");
                    if (selectAllCheckbox) selectAllCheckbox.checked = false;

                    if (successCount > 0) {
                        vt.success(`Successfully deleted ${successCount} record(s)`);
                    }
                    if (failCount > 0) {
                        vt.error(`Failed to delete ${failCount} record(s)`);
                    }

                    // Check if table is empty
                    const tbody = document.querySelector("tbody");
                    const remainingRows = tbody.querySelectorAll("tr");
                    if (remainingRows.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="100" class="text-center py-8 text-gray-500">
                                    <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
                                    No records found
                                </td>
                            </tr>
                        `;
                    }
                });
            }
        }

        function selectAllColumns() {
            const checkboxes = document.querySelectorAll(".column-toggle");
            checkboxes.forEach(cb => {
                cb.checked = true;
                toggleColumn(cb.dataset.column, true);
            });
        }

        function toggleColumn(columnName, show) {
            // Target only table headers and cells, not the checkboxes
            const headers = document.querySelectorAll(`.column-header[data-column="${columnName}"]`);
            const cells = document.querySelectorAll(`.column-cell[data-column="${columnName}"]`);

            headers.forEach(element => {
                element.style.display = show ? "" : "none";
            });

            cells.forEach(element => {
                element.style.display = show ? "" : "none";
            });
        }

        // Handle shadow effect on scroll
        document.addEventListener("DOMContentLoaded", function() {
            const tableContainer = document.getElementById("tableContainer");
            const actionsHeader = document.getElementById("actionsHeader");
            const selectAllHeader = document.getElementById("selectAllHeader");
            const actionsCells = document.querySelectorAll(".actions-cell");
            const checkboxCells = document.querySelectorAll(".checkbox-cell");
            const columnToggleBtn = document.getElementById("columnToggleBtn");
            const columnDropdown = document.getElementById("columnDropdown");

            function updateShadowEffects() {
                const isScrolled = tableContainer.scrollLeft > 0;
                const isScrolledRight = tableContainer.scrollLeft < (tableContainer.scrollWidth - tableContainer.clientWidth);

                // Right side shadows for actions (when scrolled left)
                if (actionsHeader && actionsCells.length > 0) {
                    if (isScrolled) {
                        actionsHeader.classList.add("shadow-left");
                        actionsCells.forEach(cell => cell.classList.add("shadow-left"));
                    } else {
                        actionsHeader.classList.remove("shadow-left");
                        actionsCells.forEach(cell => cell.classList.remove("shadow-left"));
                    }
                }

                // Left side shadows for checkboxes (when scrolled right)
                if (selectAllHeader && checkboxCells.length > 0) {
                    if (isScrolledRight && isScrolled) {
                        selectAllHeader.classList.add("shadow-right");
                        checkboxCells.forEach(cell => cell.classList.add("shadow-right"));
                    } else {
                        selectAllHeader.classList.remove("shadow-right");
                        checkboxCells.forEach(cell => cell.classList.remove("shadow-right"));
                    }
                }
            }

            tableContainer.addEventListener("scroll", updateShadowEffects);
            updateShadowEffects(); // Check initial state

            // Column dropdown toggle
            if (columnToggleBtn && columnDropdown) {
                columnToggleBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    columnDropdown.classList.toggle("hidden");
                });

                // Close dropdown when clicking outside
                document.addEventListener("click", function(e) {
                    if (!columnDropdown.contains(e.target) && !columnToggleBtn.contains(e.target)) {
                        columnDropdown.classList.add("hidden");
                    }
                });
            }

            // Handle column toggle checkboxes
            document.querySelectorAll(".column-toggle").forEach(checkbox => {
                checkbox.addEventListener("change", function() {
                    toggleColumn(this.dataset.column, this.checked);
                });
            });
        });

        // Handle status toggle switches with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const statusSwitches = document.querySelectorAll(".toggle-status-switch");

            statusSwitches.forEach(toggle => {
                toggle.addEventListener("change", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const column = this.dataset.column;
                    const newStatus = this.checked ? 1 : 0;
                    const switchElement = this;

                    // Disable the switch during request
                    switchElement.disabled = true;

                    // Send AJAX request
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "toggle_status",
                            table: table,
                            column: column,
                            id: id,
                            status: newStatus
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            if (column === "banned") {
                                vt.success("Banned updated successfully!");
                            } else if (column === "featured") {
                                vt.success("Featured updated successfully!");
                            } else {
                                vt.success("Status updated successfully!");
                            }
                            switchElement.disabled = false;
                            // keep the row in step: status pill, and the featured switch (only an enabled row can be featured)
                            const tr = switchElement.closest("tr");
                            if (tr && (column === "status" || column === "active")) {
                                const pill = tr.querySelector("td[data-column=\"" + column + "\"] .cr-status");
                                if (pill) {
                                    pill.classList.toggle("is-on", newStatus === 1);
                                    pill.classList.toggle("is-off", newStatus !== 1);
                                    pill.lastChild.textContent = newStatus === 1 ? "Active" : "Inactive";
                                }
                                const feat = tr.querySelector(".featured-cell input");
                                if (feat) {
                                    if (newStatus !== 1) feat.checked = false;
                                    feat.disabled = newStatus !== 1;
                                    feat.closest("label").title = newStatus === 1 ? "" : "Enable it first to feature it";
                                }
                            }
                        } else {
                            vt.error(data.message || (column === "banned" ? "Failed to update banned" : "Failed to update status"));
                            // Revert the switch on error
                            switchElement.checked = !switchElement.checked;
                            switchElement.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error updating status");
                        // Revert the switch on error
                        switchElement.checked = !switchElement.checked;
                        switchElement.disabled = false;
                    });
                });
            });
        });

        // Handle default toggle switches with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const defaultSwitches = document.querySelectorAll(".toggle-default-switch");

            defaultSwitches.forEach(toggle => {
                toggle.addEventListener("change", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const isChecked = this.checked;
                    const switchElement = this;

                    // Disable all switches during request
                    defaultSwitches.forEach(s => s.disabled = true);

                    // Send AJAX request
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "set_default",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            vt.success("Default updated successfully!");

                            // Uncheck all other switches
                            defaultSwitches.forEach(s => {
                                if (s !== switchElement) {
                                    s.checked = false;
                                }
                            });

                            // Make sure current switch is checked
                            switchElement.checked = true;

                            // Re-enable all switches
                            defaultSwitches.forEach(s => s.disabled = false);
                        } else {
                            vt.error(data.message || "Failed to update default");

                            // Find which switch should remain checked (the current default)
                            // Keep all switches in their original state
                            defaultSwitches.forEach(s => {
                                // If this was the switch that was just clicked
                                if (s === switchElement) {
                                    // If it was trying to enable (turn on), revert it back to off
                                    if (isChecked) {
                                        s.checked = false;
                                    } else {
                                        // If it was trying to disable (turn off), keep it on
                                        s.checked = true;
                                    }
                                }
                            });

                            // Re-enable all switches
                            defaultSwitches.forEach(s => s.disabled = false);
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error updating default");

                        // Revert the switch state
                        switchElement.checked = !isChecked;

                        // Re-enable all switches
                        defaultSwitches.forEach(s => s.disabled = false);
                    });
                });
            });
        });

        // Handle delete button with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const deleteButtons = document.querySelectorAll(".delete-btn");

            deleteButtons.forEach(btn => {
                btn.addEventListener("click", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const buttonElement = this;
                    const row = buttonElement.closest("tr");

                    // Disable the button during check
                    buttonElement.disabled = true;
                    buttonElement.style.opacity = "0.5";

                    // First check if record is default before showing confirmation
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "check_default",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Re-enable button
                        buttonElement.disabled = false;
                        buttonElement.style.opacity = "1";

                        if (data.is_default) {
                            // Show warning immediately if it\'s a default record
                            vt.warn(data.message || "Cannot delete the default record. Please set another record as default first.");
                            return;
                        }

                        // Not a default record, proceed with confirmation dialog
                        if (!confirm("Are you sure you want to delete this record? This action cannot be undone.")) {
                            return;
                        }

                        // Disable the button during deletion
                        buttonElement.disabled = true;
                        buttonElement.style.opacity = "0.5";

                        // Send delete request
                        fetch("' . root . 'ajax", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                            },
                            body: JSON.stringify({
                                action: "delete_record",
                                table: table,
                                id: id
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === "success") {
                                vt.success(data.message || "Record deleted successfully!");

                                // Remove the row from the table with fade out effect
                                row.style.transition = "all 0.4s ease-out";
                                row.style.opacity = "0";
                                row.style.transform = "translateX(-20px)";

                                setTimeout(() => {
                                    row.remove();

                                    // Check if table is empty
                                    const tbody = document.querySelector("tbody");
                                    const remainingRows = tbody.querySelectorAll("tr");
                                    if (remainingRows.length === 0) {
                                        // Show empty state message
                                        tbody.innerHTML = `
                                            <tr>
                                                <td colspan="100" class="text-center py-8 text-gray-500">
                                                    <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
                                                    No records found
                                                </td>
                                            </tr>
                                        `;
                                    }
                                }, 400);
                            } else if (data.status === "warning") {
                                vt.warn(data.message || "Cannot delete this record");
                                buttonElement.disabled = false;
                                buttonElement.style.opacity = "1";
                            } else {
                                vt.error(data.message || "Failed to delete record");
                                buttonElement.disabled = false;
                                buttonElement.style.opacity = "1";
                            }
                        })
                        .catch(error => {
                            console.error("Error:", error);
                            vt.error("Error deleting record");
                            buttonElement.disabled = false;
                            buttonElement.style.opacity = "1";
                        });
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error checking record");
                        buttonElement.disabled = false;
                        buttonElement.style.opacity = "1";
                    });
                });
            });
        });
        </script>

';
        $html .= self::styles();
        return $html;
    }

    /**
     * DESIGN-SYSTEM STYLES FOR THE LIST VIEW (pt README §47: square corners, 1px hairlines,
     * one accent = the theme colour, spaced-caps column labels, tabular numbers). Printed once
     * per page, however many tables it renders.
     *
     * CLASSES
     *   .cr                        frame;  .cr-head / .cr-head-row / .cr-title / .cr-meta  header
     *   .cr-tools                  toolbar: .cr-cols (+ .cr-pop column picker), .cr-search, buttons
     *   .cr-btn                    40px square button; -primary / -ghost / -danger / -square
     *   .cr-field / .cr-select     40px square inputs
     *   .cr-scroll / .cr-table     table; .cr-c-check / -num / -switch / -actions column kinds
     *   .cr-switch + .cr-track     the round toggle (.is-danger for "banned")
     *   .cr-status                 Active / Inactive pill;  .cr-none  empty value dash
     *   .cr-acts / .cr-act         32px row actions (.is-danger, .has-label)
     *   .cr-empty                  no records;  .cr-foot / .cr-pages / .cr-page  pagination
     */
    private static $stylesPrinted = false;

    private static function styles() {
        if (self::$stylesPrinted) return '';
        self::$stylesPrinted = true;
        return '
        <style>
        .cr {
            --cr-canvas: #f6f8fc; --cr-surface: #ffffff; --cr-hover: #f1f4fa; --cr-line: #e4e9f2;
            --cr-ink: #0b1220; --cr-mute: #5b6578; --cr-accent: var(--color-primary, #0052e7);
            --cr-tint: color-mix(in srgb, var(--cr-accent) 9%, var(--cr-surface));
            border: 1px solid var(--cr-line); background: var(--cr-surface); color: var(--cr-ink);
        }
        .cr .material-symbols-outlined { font-size: 18px; font-variation-settings: "FILL" 0, "wght" 350, "GRAD" 0, "opsz" 20; }
        .cr :focus-visible { outline: 2px solid var(--cr-accent); outline-offset: -2px; }

        /* HEAD + TOOLBAR */
        .cr-head { padding: 16px 18px; border-bottom: 1px solid var(--cr-line); background: var(--cr-canvas); }
        .cr-head-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 20px; }
        .cr-title { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
        .cr-meta { margin: 2px 0 0; color: var(--cr-mute); font-size: 13px; font-variant-numeric: tabular-nums; }
        .cr-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .cr-search { display: flex; flex-wrap: wrap; align-items: center; gap: 0; }
        .cr-search > * + * { margin-inline-start: -1px; }
        .cr-field {
            height: 40px; padding: 0 12px; border: 1px solid var(--cr-line); border-radius: 0; background: var(--cr-surface);
            color: var(--cr-ink); font-size: 14px; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .cr-field:not(.cr-select) { width: 220px; }
        .cr-select { width: 150px; padding-inline-end: 28px; appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%235b6578%27 stroke-width=%272%27%3E%3Cpath d=%27m6 9 6 6 6-6%27/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 9px center; background-size: 14px; }
        [dir="rtl"] .cr-select { background-position: left 9px center; }
        .cr-field:focus { position: relative; z-index: 1; border-color: var(--cr-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--cr-accent) 20%, transparent); }
        .cr-btn {
            height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 16px;
            border: 1px solid var(--cr-line); border-radius: 0; background: var(--cr-surface); color: var(--cr-ink);
            font-size: 14px; font-weight: 600; white-space: nowrap; text-decoration: none; cursor: pointer; transition: background-color .15s, border-color .15s, color .15s;
        }
        .cr-btn:hover { border-color: color-mix(in srgb, var(--cr-accent) 45%, var(--cr-line)); color: var(--cr-accent); }
        .cr-btn-square { width: 40px; padding: 0; }
        .cr-btn-primary { border-color: var(--cr-accent); background: var(--cr-accent); color: #fff; }
        .cr-btn-primary:hover { background: color-mix(in srgb, var(--cr-accent) 85%, #000); color: #fff; }
        .cr-search .cr-btn-primary, .cr-search .cr-btn-square:not(.cr-btn-ghost) { border-color: var(--cr-accent); background: var(--cr-accent); color: #fff; }
        .cr-btn-danger { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
        .cr-btn-danger:hover { border-color: #dc2626; background: #dc2626; color: #fff; }
        .cr-cols { position: relative; }
        .cr-pop { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); z-index: 50; width: 240px; max-height: 360px; overflow: auto;
            padding: 12px; background: var(--cr-surface); border: 1px solid var(--cr-line); box-shadow: 0 14px 34px -14px rgba(11, 18, 32, .28); }
        .cr-pop > div > div:first-child { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .cr-pop-title { margin: 0; color: var(--cr-mute); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .cr-link { border: 0; background: none; padding: 0; color: var(--cr-accent); font-size: 12.5px; font-weight: 600; cursor: pointer; }
        .cr-check { display: flex; align-items: center; gap: 10px; height: 32px; font-size: 13.5px; cursor: pointer; }
        .cr #columnCheckboxes { display: flex; flex-direction: column; }

        /* CHECKBOXES: SQUARE, ACCENT WHEN ON */
        .cr input[type="checkbox"]:not(.toggle-status-switch):not(.toggle-default-switch) {
            width: 16px; height: 16px; margin: 0; border-radius: 0; accent-color: var(--cr-accent); cursor: pointer;
        }

        /* TABLE */
        .cr-scroll { position: relative; overflow-x: auto; }
        .cr-table { width: 100%; min-width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13.5px; }
        .cr-table th {
            height: 44px; padding: 0 14px; background: var(--cr-canvas); border-bottom: 1px solid var(--cr-line);
            color: var(--cr-mute); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; text-align: start; white-space: nowrap;
        }
        .cr-table td { height: 48px; padding: 8px 14px; border-bottom: 1px solid var(--cr-line); background: var(--cr-surface); white-space: nowrap; font-variant-numeric: tabular-nums; vertical-align: middle; transition: background-color .15s; }
        .cr-table tbody tr:last-child td { border-bottom: 0; }
        .cr-table tbody tr:hover td { background: var(--cr-hover); }
        .cr-table tbody tr.is-selected td { background: var(--cr-tint); }
        .cr-table tbody tr.is-selected td:first-child { box-shadow: inset 2px 0 0 var(--cr-accent); }
        .cr-c-check { width: 44px; text-align: center !important; }
        .cr-c-num { width: 52px; color: var(--cr-mute); }
        td.cr-c-num { font-size: 12.5px; }
        .cr-c-switch { width: 76px; text-align: center !important; }
        .cr-c-actions { text-align: end !important; }
        .cr-none { color: var(--cr-mute); }
        .cr-table img { max-height: 40px; }

        /* STICKY START (CHECKBOX) AND END (ACTIONS) COLUMNS */
        .cr .checkbox-cell { position: sticky; left: 0; z-index: 2; }
        .cr .actions-cell { position: sticky; right: 0; z-index: 3; border-inline-start: 1px solid var(--cr-line); }
        .cr .shadow-left { box-shadow: -12px 0 14px -12px rgba(11, 18, 32, .3); }
        .cr .shadow-right { box-shadow: 12px 0 14px -12px rgba(11, 18, 32, .3); }

        /* TOGGLE: THE ONE ROUND CONTROL */
        .cr-switch { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .cr-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .cr-track { position: relative; width: 36px; height: 20px; border-radius: 999px; background: #d6dce8; transition: background-color .2s; }
        .cr-track::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(11, 18, 32, .25); transition: transform .2s cubic-bezier(.22, 1, .36, 1); }
        .cr-switch input:checked + .cr-track { background: var(--cr-accent); }
        .cr-switch input:checked + .cr-track.is-danger { background: #dc2626; }
        .cr-switch input:checked + .cr-track::after { transform: translateX(16px); }
        .cr-switch input:focus-visible + .cr-track { outline: 2px solid var(--cr-accent); outline-offset: 2px; }
        .cr-switch input:disabled + .cr-track { opacity: .5; }
        .cr-switch:has(input:disabled) { cursor: not-allowed; }

        /* STATUS PILL (STATUS COLOURS ONLY FOR STATUS) */
        .cr-status { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 8px; border: 1px solid var(--cr-line); font-size: 12px; font-weight: 600; }
        .cr-status i { width: 7px; height: 7px; border-radius: 50%; }
        .cr-status.is-on { color: #047857; border-color: #a7f3d0; background: #ecfdf5; }
        .cr-status.is-on i { background: #059669; }
        .cr-status.is-off { color: var(--cr-mute); }
        .cr-status.is-off i { background: #94a3b8; }

        /* ROW ACTIONS */
        .cr-acts { display: inline-flex; align-items: center; gap: 4px; }
        .cr-act {
            min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 7px;
            border: 1px solid var(--cr-line); border-radius: 0; background: var(--cr-surface); color: var(--cr-mute);
            font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: background-color .15s, border-color .15s, color .15s;
        }
        .cr-act.has-label { padding: 0 10px; }
        .cr-act:hover { border-color: color-mix(in srgb, var(--cr-accent) 45%, var(--cr-line)); background: var(--cr-tint); color: var(--cr-accent); }
        .cr-act.is-danger:hover { border-color: #fca5a5; background: #fef2f2; color: #dc2626; }
        /* PAGES PASS THEIR OWN COLOUR / ROUNDING UTILITIES (SOME !important) TO CUSTOM BUTTONS: INSIDE THE
           TABLE EVERY ACTION IS THE SAME SQUARE NEUTRAL BUTTON; RED ONES GET .is-danger IN PHP */
        .cr .cr-acts .cr-act { height: 32px !important; border-radius: 0 !important; border-color: var(--cr-line) !important; background: var(--cr-surface) !important; color: var(--cr-mute) !important; }
        .cr .cr-acts .cr-act:not(.has-label) { min-width: 32px !important; padding: 0 7px !important; }
        .cr .cr-acts .cr-act:hover { border-color: color-mix(in srgb, var(--cr-accent) 45%, var(--cr-line)) !important; background: var(--cr-tint) !important; color: var(--cr-accent) !important; }
        .cr .cr-acts .cr-act.is-danger:hover { border-color: #fca5a5 !important; background: #fef2f2 !important; color: #dc2626 !important; }
        .cr-act:disabled { opacity: .4; cursor: not-allowed; }
        .cr-act .material-symbols-outlined { font-size: 18px; }

        /* EMPTY */
        .cr-empty { padding: 56px 20px; text-align: center; }
        .cr-empty-icon { width: 48px; height: 48px; display: grid; place-items: center; margin: 0 auto 14px; border: 1px solid var(--cr-line); color: var(--cr-mute); }
        .cr-empty-icon .material-symbols-outlined { font-size: 24px; }
        .cr-empty h3 { margin: 0 0 4px; font-size: 15px; font-weight: 700; }
        .cr-empty p { margin: 0 0 18px; color: var(--cr-mute); font-size: 13.5px; }

        /* PAGINATION */
        .cr-foot { padding: 12px 18px; border-top: 1px solid var(--cr-line); background: var(--cr-canvas); }
        .cr-foot-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 20px; }
        .cr-foot .cr-meta { margin: 0; }
        .cr-pages { display: flex; flex-wrap: wrap; align-items: center; }
        .cr-pages > * + * { margin-inline-start: -1px; }
        .cr-page {
            min-width: 36px; height: 36px; display: inline-grid; place-items: center; padding: 0 10px; border: 1px solid var(--cr-line);
            background: var(--cr-surface); color: var(--cr-ink); font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; text-decoration: none; transition: background-color .15s, color .15s;
        }
        .cr-page:hover { position: relative; z-index: 1; background: var(--cr-tint); color: var(--cr-accent); }
        .cr-page.is-current { position: relative; z-index: 2; border-color: var(--cr-accent); background: var(--cr-accent); color: #fff; }
        .cr-gap { min-width: 36px; height: 36px; display: inline-grid; place-items: center; border: 1px solid var(--cr-line); background: var(--cr-surface); color: var(--cr-mute); }

        .cr-form { padding: 20px 18px; }
        .cr-form-body { display: grid; gap: 18px; }
        .cr-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
        .cr-field.cr-wide { width: 100%; }
        textarea.cr-field { height: auto; min-height: 110px; padding: 10px 12px; }
        .cr-field[readonly] { background: var(--cr-canvas); color: var(--cr-mute); }
        .cr-form-actions { display: flex; justify-content: flex-end; gap: 8px; padding-top: 16px; border-top: 1px solid var(--cr-line); }

        @media (max-width: 639px) {
            .cr-tools, .cr-search { width: 100%; }
            .cr-field:not(.cr-select) { flex: 1; width: auto; min-width: 0; }
            .cr-tools > .cr-btn, .cr-tools > #bulkActions, .cr-tools > #bulkActions .cr-btn { flex: 1; }
        }
        @media (prefers-reduced-motion: reduce) { .cr-track, .cr-track::after { transition: none; } }
        </style>';
    }

    private function form($cols, $data, $action) {
        $html = '
        <div class="cr">
            <div class="cr-head">
                <h2 class="cr-title">' . ucfirst($action) . ' ' . rtrim($this->title, 's') . '</h2>
            </div>
            <div class="cr-form">
                <form method="post" class="cr-form-body">
                    <input type="hidden" name="action" value="' . ($action == 'edit' ? 'update' : 'create') . '">';

        if ($action == 'edit') {
            $html .= '<input type="hidden" name="id" value="' . htmlspecialchars($data['id'] ?? '') . '">';
        }

        foreach ($cols as $col) {
            if ($col == 'id' && $action == 'create') continue;

            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $value = htmlspecialchars(($data[$col] ?? '') ?: '');
            $readonly = ($col == 'id' || $action == 'view') ? 'readonly' : '';
            $required = ($col !== 'id' && $action !== 'view') ? 'required' : '';

            $html .= '
                    <div>
                        <label for="' . $col . '" class="cr-label">' . $label . '</label>';

            if (strpos($col, 'description') !== false || strpos($col, 'content') !== false || strpos($col, 'text') !== false) {
                $html .= '<textarea name="' . $col . '" id="' . $col . '" rows="4" ' . $readonly . ' ' . $required . '
                                    class="cr-field cr-wide">' . $value . '</textarea>';
            } else {
                $type = 'text';
                if (strpos($col, 'email') !== false) $type = 'email';
                elseif (strpos($col, 'password') !== false) $type = 'password';
                elseif (strpos($col, 'date') !== false) $type = 'date';
                elseif (strpos($col, 'time') !== false) $type = 'time';
                elseif (strpos($col, 'url') !== false) $type = 'url';

                $html .= '<input type="' . $type . '" name="' . $col . '" id="' . $col . '" value="' . $value . '" ' . $readonly . ' ' . $required . '
                                class="cr-field cr-wide">';
            }

            $html .= '</div>';
        }

        $html .= '
                    <div class="cr-form-actions">
                        <a href="../" class="cr-btn">
                            ' . ($action == 'view' ? 'Back' : 'Cancel') . '
                        </a>';

        if ($action !== 'view') {
            $html .= '
                        <button type="submit" class="cr-btn cr-btn-primary">
                            ' . ucfirst($action) . ' Record
                        </button>';
        }

        $html .= '
                    </div>
                </form>
            </div>
        </div>';

        return $html . self::styles();
    }
}

// Global instance
$GLOBALS['crud'] = new CRUD();

// Helper function to get CRUD instance without using global keyword
function crud() {
    return $GLOBALS['crud'];
}

return $GLOBALS['crud'];