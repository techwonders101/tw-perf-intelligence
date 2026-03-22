# Security Review Checklist

## Quick Security Audit Checklist

Use this checklist for rapid security assessments of code changes.

### Pre-Review Preparation
- [ ] Read security.md for project context
- [ ] Identify files changed
- [ ] Understand the feature/fix being reviewed

---

## Code Review Checklist

### 1. Input Handling (CRITICAL)
- [ ] All `$_GET` inputs sanitized before use
- [ ] All `$_POST` inputs sanitized before use
- [ ] All `$_REQUEST` inputs sanitized before use
- [ ] All `$_SERVER` inputs sanitized before use
- [ ] `wp_unslash()` applied before sanitization
- [ ] Appropriate sanitization function used (text, key, int, etc.)

### 2. Database Operations (CRITICAL)
- [ ] `$wpdb->prepare()` used for all parameterized queries
- [ ] No direct user input in SQL strings
- [ ] `absint()` used for ID parameters
- [ ] phpcs:ignore comments explain safe patterns

### 3. Output Escaping (HIGH)
- [ ] `esc_html()` for text in HTML
- [ ] `esc_attr()` for attribute values
- [ ] `esc_url()` for URLs in HTML
- [ ] `esc_js()` for JavaScript strings
- [ ] `wp_json_encode()` for JSON responses

### 4. Authorization (CRITICAL)
- [ ] Capability checks (`current_user_can()`)
- [ ] REST API permission callbacks
- [ ] Admin menu capability requirements
- [ ] WooCommerce-specific capabilities used

### 5. Authentication (CRITICAL)
- [ ] Nonce verification on form submissions
- [ ] Nonce verification on AJAX handlers
- [ ] API key validation for REST endpoints
- [ ] OAuth credential validation

### 6. File Security (MEDIUM)
- [ ] `ABSPATH` check at file top
- [ ] No dynamic file includes with user input
- [ ] Safe file path handling

### 7. Error Handling (MEDIUM)
- [ ] No sensitive data in error messages
- [ ] Proper error logging
- [ ] Graceful failure handling

### 8. Data Exposure (HIGH)
- [ ] No secrets in responses
- [ ] No debug output in production
- [ ] Sensitive fields excluded from API

---

## File-Specific Checklists

### REST API Endpoints
- [ ] Permission callback defined
- [ ] Write operations require write permission
- [ ] Input parameters sanitized
- [ ] Response data properly formatted
- [ ] Errors don't expose internals

### Admin Pages
- [ ] Menu callback has capability check
- [ ] Form uses `wp_nonce_field()`
- [ ] Handler verifies nonce
- [ ] Handler checks capability
- [ ] Output escaped in templates

### AJAX Handlers
- [ ] `check_ajax_referer()` called
- [ ] User capability verified
- [ ] Input sanitized
- [ ] Response properly formatted
- [ ] `wp_die()` at end

### Cron Jobs
- [ ] No user input involved
- [ ] Database queries are safe
- [ ] Logging doesn't expose secrets
- [ ] Failure is handled gracefully

### Webhooks (Outbound)
- [ ] HMAC signature generated
- [ ] Timestamp included
- [ ] SSL verification enabled
- [ ] Payload sanitized
- [ ] Errors logged securely

---

## Testing Verification

### Automated Tests
- [ ] PHP syntax validation passed
- [ ] PHPCS security standards passed
- [ ] Unit tests passed

### Manual Tests
- [ ] Feature works as expected
- [ ] Invalid input handled properly
- [ ] Unauthorized access rejected
- [ ] Error messages are safe

---

## Post-Review Actions

### If Issues Found
- [ ] Document findings with severity
- [ ] Propose specific fixes
- [ ] Update security.md if needed
- [ ] Add to CHANGELOG if fixed

### If No Issues
- [ ] Note review completion
- [ ] Update documentation if needed

---

## Severity Ratings

| Severity | Examples | Response Time |
|----------|----------|---------------|
| **Critical** | SQL Injection, Auth Bypass, RCE | Immediate fix |
| **High** | XSS, CSRF, Data Exposure | Fix within 24h |
| **Medium** | Info Disclosure, Missing Validation | Fix within week |
| **Low** | Best Practice Violations | Fix when convenient |

---

## Quick Commands

```bash
# PHP syntax check
find . -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# Find unsanitized $_GET usage
grep -rn '\$_GET\[' --include="*.php" | grep -v 'sanitize\|absint\|wp_unslash'

# Find unsanitized $_POST usage
grep -rn '\$_POST\[' --include="*.php" | grep -v 'sanitize\|absint\|wp_unslash'

# Find direct database queries without prepare
grep -rn '\$wpdb->query\|get_var\|get_row\|get_results' --include="*.php" | grep -v 'prepare'

# Find unescaped output
grep -rn 'echo \$\|print \$' --include="*.php" | grep -v 'esc_'
```
