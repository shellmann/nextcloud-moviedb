# Security Policy

## Supported Versions

We currently provide security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.4.x   | :white_check_mark: |
| 1.3.x   | :white_check_mark: |
| < 1.3   | :x:                |

## Reporting a Vulnerability

We take the security of MovieDB seriously. If you discover a security vulnerability, please follow these steps:

### Reporting Process

1. **DO NOT** open a public GitHub issue for security vulnerabilities
2. **DO** report the vulnerability privately through one of these methods:
   - Open a private security advisory on GitHub (preferred)
   - Contact via GitHub discussions (mark as sensitive)

3. **Include the following information**:
   - Description of the vulnerability
   - Steps to reproduce the issue
   - Potential impact
   - Suggested fix (if you have one)
   - Your contact information

### What to Expect

- **Acknowledgment**: I will try to acknowledge receipt of your report when I can
- **Assessment**: I will assess the vulnerability and determine its severity when possible
- **Updates**: I will try to keep you informed about progress
- **Resolution**: I will try to work on fixes as my schedule allows - critical issues will be prioritized when feasible
- **Credit**: If you wish, I will credit you in the security advisory (unless you prefer to remain anonymous)

**Note**: This is a personal hobby project maintained in my spare time. While I take security seriously and will try to address issues, I cannot guarantee response times or fix timelines. Please understand this is a volunteer effort without dedicated support resources.

### Security Best Practices for Users

When using MovieDB:

1. **Keep Updated**: Always use the latest version of the app
2. **Secure API Keys**: Store your TMDB API key securely - never share it publicly
3. **Regular Backups**: Backup your Nextcloud instance regularly
4. **HTTPS Only**: Always access Nextcloud over HTTPS
5. **Strong Authentication**: Use strong passwords and 2FA for your Nextcloud account

### Known Security Considerations

- **TMDB API Key**: Your TMDB API key is stored in Nextcloud's encrypted user settings
- **Data Privacy**: Movie data is private to each user by default. Shared libraries allow users to intentionally share their collection with specific other Nextcloud users — access is controlled by the library owner via role assignments (viewer/editor)
- **XSS Protection**: All user input is sanitized to prevent cross-site scripting
- **SQL Injection**: We use Nextcloud's QueryBuilder to prevent SQL injection attacks
- **CSRF Protection**: All forms use Nextcloud's built-in CSRF protection

### Security Features

- ✅ Strict typing in PHP code (`declare(strict_types=1)`)
- ✅ Input validation on all API endpoints
- ✅ Database abstraction layer (no raw SQL)
- ✅ Content Security Policy (CSP) headers
- ✅ User data isolation per Nextcloud user
- ✅ No external data transmission except to TMDB API
- ✅ AGPL-3.0 license ensures code transparency

## Disclosure Policy

- We follow responsible disclosure practices
- Security advisories will be published on GitHub Security Advisories
- Critical vulnerabilities will be announced in release notes
- We will credit security researchers (unless anonymity is requested)

## Questions

If you have questions about this security policy, please open a GitHub discussion.

Thank you for helping keep MovieDB and its users safe!
