---
name: security-advisor
description: Comprehensive security advisor for the Stokkap Connect WordPress plugin. Use this skill for security audits, vulnerability detection, code review for security issues, fixing security problems, and suggesting security improvements. Triggers on security review, vulnerability assessment, security audit, penetration testing, security hardening, and OWASP compliance checks.
allowed-tools: Read, Grep, Glob, Bash(php -l:*), Bash(composer:*)
---

# Security Advisor Skill

## Purpose

This skill provides comprehensive security analysis and remediation for the Stokkap Connect WordPress/WooCommerce plugin. It follows WordPress security best practices, OWASP guidelines, and the project-specific security documentation.

## Reference Documents

Before starting any security review, read these key files:
- [security.md](../../../security.md) - Project security documentation and guidelines
- [readme.txt](../../../readme.txt) - Plugin documentation and changelog context
- [CHANGELOG.md](../../../CHANGELOG.md) - Version history and past security fixes

## Security Review Process

### 1. Initial Assessment

When asked to perform a security review:

1. Read `security.md` to understand current security posture
2. Check `CHANGELOG.md` for recent security-related changes
3. Identify the scope (full codebase, specific file, or feature)

### 2. Core Security Checks

#### Authentication & Authorization
- [ ] REST API endpoints use proper permission callbacks (`check_permission`, `check_write_permission`)
- [ ] WooCommerce API key authentication is properly validated
- [ ] Admin actions verify `current_user_can('manage_woocommerce')`
- [ ] OAuth callback from Stokkap validates credentials properly

#### Input Validation & Sanitization
- [ ] All `$_GET`, `$_POST`, `$_REQUEST` inputs are sanitized
- [ ] Use `sanitize_text_field()` for text inputs
- [ ] Use `sanitize_key()` for slugs and identifiers
- [ ] Use `absint()` for numeric IDs
- [ ] Use `esc_url_raw()` for URLs
- [ ] Use `wp_unslash()` before sanitization

#### Output Escaping
- [ ] Use `esc_html()` for HTML content
- [ ] Use `esc_attr()` for HTML attributes
- [ ] Use `esc_url()` for URLs in HTML
- [ ] Use `esc_js()` for JavaScript strings
- [ ] Use `wp_json_encode()` for JSON output

#### SQL Injection Prevention
- [ ] All database queries use `$wpdb->prepare()`
- [ ] Table names from `$wpdb->prefix` are safe but documented with phpcs:ignore
- [ ] No direct string concatenation in SQL queries with user input

#### Nonce Verification
- [ ] Admin form submissions verify nonces with `wp_verify_nonce()`
- [ ] Delete actions use nonce URLs with `wp_nonce_url()`
- [ ] REST API uses WooCommerce API key auth (nonces not applicable)
- [ ] OAuth callbacks from external services are properly documented

#### Cross-Site Scripting (XSS)
- [ ] All output in admin templates is escaped
- [ ] JavaScript variables are properly escaped
- [ ] AJAX responses use proper content-type headers

#### File Operations
- [ ] No direct file inclusion with user input
- [ ] `ABSPATH` check at top of all PHP files
- [ ] No eval() or similar dangerous functions

### 3. Plugin-Specific Security Areas

#### REST API (`class-st-rest-api.php`)
- Custom namespace `/stokkap/v1/`
- WooCommerce API key authentication for external access
- Permission callbacks: `check_permission` (read), `check_write_permission` (write)
- Stokkap source header detection to prevent webhook loops

#### Webhooks (`class-st-webhooks.php`)
- HMAC signature generation for outbound webhooks
- `X-Stokkap-Signature` header with SHA-256 HMAC
- Timestamp included to prevent replay attacks
- SSL verification enabled (`sslverify => true`)

#### Admin Interface (`class-st-admin.php`)
- Nonce verification on all form submissions
- Capability checks (`manage_woocommerce`)
- OAuth callback handling with credential validation

#### Database Operations
All custom tables (`st_locations`, `st_location_types`, `st_stock_log`, `st_webhook_log`, `st_webhook_queue`):
- Use `$wpdb->prepare()` for parameterized queries
- Direct queries documented with phpcs:ignore comments
- Schema modifications only during upgrades

### 4. Testing Procedures

#### Syntax Validation
```bash
# Validate PHP syntax for all files
find . -name "*.php" -exec php -l {} \;
```

#### PHPCS Security Checks
```bash
# Run WordPress Coding Standards security checks
composer run phpcs -- --standard=WordPress-Extra
```

#### Manual Testing Checklist
1. Test API endpoints without authentication (should fail)
2. Test API endpoints with invalid credentials (should fail)
3. Test admin pages without login (should redirect)
4. Test form submissions without nonces (should fail)
5. Test with special characters in inputs
6. Test with SQL injection payloads in search/filter params

### 5. Common Vulnerability Patterns

#### Watch For:
- Direct database queries without `$wpdb->prepare()`
- Output without escaping functions
- Missing nonce verification on POST handlers
- Missing capability checks on admin actions
- Unvalidated redirects
- Information disclosure in error messages
- Debug mode enabled in production

#### Known Safe Patterns:
- `$wpdb->prefix . 'table_name'` - Safe, table name is controlled
- WooCommerce hooks for nonce verification (documented with phpcs:ignore)
- REST API permission callbacks for authentication

### 6. Remediation Guidelines

When fixing security issues:

1. **Prioritize**: Critical (RCE, SQLi, Auth bypass) > High (XSS, CSRF) > Medium > Low
2. **Test**: Verify fix works and doesn't break functionality
3. **Document**: Update security.md with finding and resolution
4. **Review**: Check for similar patterns elsewhere in codebase

### 7. Security Improvement Suggestions

After completing a security review, consider:

1. **Rate Limiting**: Add rate limiting to REST API endpoints
2. **Logging**: Enhance security event logging
3. **Headers**: Add security headers (CSP, X-Frame-Options)
4. **Audit Trail**: Log all admin actions for accountability
5. **Secrets Management**: Review storage of API credentials

## OWASP Top 10 Quick Reference

1. **Injection** - Use prepared statements, validate input
2. **Broken Authentication** - Verify user capabilities, secure tokens
3. **Sensitive Data Exposure** - Encrypt at rest/transit, mask secrets
4. **XML External Entities** - Not applicable (no XML parsing)
5. **Broken Access Control** - Permission callbacks, capability checks
6. **Security Misconfiguration** - Check debug mode, error display
7. **XSS** - Escape all output, validate input
8. **Insecure Deserialization** - Avoid unserialize() on user input
9. **Using Components with Known Vulnerabilities** - Keep dependencies updated
10. **Insufficient Logging** - Log security events, monitor logs

## Example Security Review Output

When reporting findings, use this format:

```
## Security Finding: [Title]

**Severity**: Critical/High/Medium/Low
**Location**: [file:line]
**Type**: [SQL Injection/XSS/CSRF/etc.]

### Description
[What the vulnerability is]

### Proof of Concept
[How to exploit, if applicable]

### Recommended Fix
[Code changes needed]

### References
- [Link to relevant documentation]
```
