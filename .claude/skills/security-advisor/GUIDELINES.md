# Stokkap Connect Security Guidelines

## WordPress Security Best Practices

### Data Validation & Sanitization

#### Input Functions Reference

| Function | Use Case | Example |
|----------|----------|---------|
| `sanitize_text_field()` | General text input | Form fields, names |
| `sanitize_key()` | Slugs, keys, identifiers | Location slugs, option names |
| `sanitize_email()` | Email addresses | User emails |
| `sanitize_file_name()` | File names | Upload handling |
| `sanitize_title()` | Post titles | Product names |
| `absint()` | Positive integers | IDs, quantities |
| `intval()` | Any integer | Can be negative |
| `floatval()` | Decimal numbers | Prices |
| `esc_url_raw()` | URLs for database storage | Webhook URLs |
| `wp_kses()` | HTML with allowed tags | Rich content |
| `wp_kses_post()` | Post-like HTML content | Descriptions |

#### Output Functions Reference

| Function | Use Case | Example |
|----------|----------|---------|
| `esc_html()` | Text in HTML context | Content display |
| `esc_attr()` | HTML attribute values | Input values |
| `esc_url()` | URLs in HTML | Link hrefs |
| `esc_js()` | JavaScript strings | Inline JS |
| `esc_textarea()` | Textarea content | Form textareas |
| `wp_json_encode()` | JSON output | API responses |

### Database Security

#### Prepared Statements Pattern

```php
// CORRECT - Using $wpdb->prepare()
$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$this->table_name} WHERE slug = %s",
    $slug
));

// CORRECT - Multiple placeholders
$wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$this->table_name} WHERE status = %s AND type = %s LIMIT %d",
    $status,
    $type,
    $limit
));

// INCORRECT - Direct interpolation with user input
$wpdb->get_var("SELECT * FROM table WHERE id = " . $_GET['id']); // DANGEROUS!
```

#### Safe Table Name Usage

```php
// Table names from $wpdb->prefix are safe
$this->table_name = $wpdb->prefix . 'st_locations';

// When using in queries, add phpcs:ignore comment explaining why it's safe
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
$wpdb->get_results("SELECT * FROM {$this->table_name}");
```

### Nonce Verification

#### Form Submission Pattern

```php
// In form template
wp_nonce_field('st_action_name', 'st_nonce');

// In handler
if (isset($_POST['st_action'], $_POST['st_nonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['st_nonce'])), 'st_action_name')) {
    // Process form
}
```

#### Delete Link Pattern

```php
// Generate nonce URL
$delete_url = wp_nonce_url(
    admin_url('admin.php?page=stokkap-locations&st_delete_location=' . $location->id),
    'st_delete_location'
);

// Verify in handler
if (isset($_GET['st_delete_location'], $_GET['_wpnonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'st_delete_location')) {
    // Process deletion
}
```

### REST API Security

#### Permission Callbacks

```php
// Read permission - requires manage_woocommerce capability
public function check_permission($request) {
    return current_user_can('manage_woocommerce');
}

// Write permission - additional API key permission check
public function check_write_permission($request) {
    if (!current_user_can('manage_woocommerce')) {
        return false;
    }
    // Additional checks for API key write permissions
    return true;
}
```

#### API Key Authentication

The plugin supports WooCommerce API key authentication:
- Query string: `?consumer_key=ck_xxx&consumer_secret=cs_xxx`
- Basic Auth: `Authorization: Basic base64(ck_xxx:cs_xxx)`
- PHP_AUTH headers

### Webhook Security

#### Outbound Webhook Signature

```php
// Generate HMAC signature
$timestamp = time();
$data = $timestamp . '.' . wp_json_encode($payload);
$signature = 'sha256=' . hash_hmac('sha256', $data, $client_secret);

// Headers sent with webhook
'X-Stokkap-Client-ID' => $client_id,
'X-Stokkap-Timestamp' => $timestamp,
'X-Stokkap-Signature' => $signature,
```

#### Preventing Webhook Loops

```php
// Source header detection
const STOKKAP_SOURCE_HEADER = 'X-Stokkap-Source';

// Check if request is from Stokkap app
private function check_stokkap_source_header() {
    $header_value = isset($_SERVER['HTTP_X_STOKKAP_SOURCE'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_STOKKAP_SOURCE']))
        : '';
    if (!empty($header_value)) {
        self::$is_stokkap_request = true;
    }
}
```

### Error Handling

#### Safe Error Messages

```php
// CORRECT - Generic error to user, detailed log
stokkap_log('Detailed error: ' . $error->getMessage(), 'error');
return new WP_Error('operation_failed', __('Operation failed. Please try again.', 'stokkap-connect'));

// INCORRECT - Exposing internal details
return new WP_Error('db_error', 'MySQL Error: ' . $wpdb->last_error);
```

### File Security

#### Top of Every PHP File

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}
```

#### Uninstall File Security

```php
<?php
// Only run from WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
```

## Common Vulnerability Patterns in This Codebase

### Patterns That Are Safe (with explanation)

1. **Direct SQL with table names**: `$wpdb->prefix . 'table'` is safe because prefix is controlled
2. **WooCommerce hook nonces**: WooCommerce validates nonces on its hooks, documented with phpcs:ignore
3. **REST API without nonces**: Uses API key authentication instead
4. **OAuth callback without nonce**: External service callbacks use credential validation

### Areas Requiring Extra Attention

1. **Admin AJAX handlers**: Must verify nonces and capabilities
2. **Product meta updates**: Ensure only valid meta keys are used
3. **Location slug handling**: Always sanitize with `sanitize_key()`
4. **Bulk operations**: Validate each item in array operations

## Security Testing Checklist

### Authentication Testing
- [ ] Access admin pages without login → should redirect
- [ ] Access REST API without credentials → should return 401
- [ ] Access REST API with invalid credentials → should return 401/403
- [ ] Access write endpoints with read-only API key → should return 403

### Authorization Testing
- [ ] Access admin pages with subscriber role → should show error
- [ ] Attempt to modify another product's stock → should verify ownership
- [ ] Delete location with stock assigned → should prevent/warn

### Input Validation Testing
- [ ] Submit forms with empty required fields → should validate
- [ ] Submit forms with special characters → should sanitize
- [ ] Submit forms with SQL injection payloads → should escape
- [ ] Submit API requests with malformed JSON → should handle gracefully

### CSRF Testing
- [ ] Submit forms without nonce → should reject
- [ ] Submit forms with expired nonce → should reject
- [ ] Submit forms with wrong nonce action → should reject

### XSS Testing
- [ ] Add location with `<script>` in name → should escape
- [ ] Add location type with HTML in label → should escape
- [ ] View logs with injected content → should escape

## Incident Response

If a security vulnerability is discovered:

1. **Assess severity** using CVSS scoring
2. **Contain** by disabling affected feature if critical
3. **Patch** the vulnerability
4. **Test** the fix thoroughly
5. **Deploy** the update
6. **Document** in CHANGELOG.md and security.md
7. **Notify** users if data exposure occurred
