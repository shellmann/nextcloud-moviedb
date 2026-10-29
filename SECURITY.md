# Security Policy

## Supported Versions

Security fixes go into the latest minor release and ship as a new patch release (for example 1.10.1). Please update to the latest version before you report an issue.

| Version | Supported          |
| ------- | ------------------ |
| 1.10.x  | :white_check_mark: |
| < 1.10  | :x:                |

## Reporting a Vulnerability

I take the security of MovieDB seriously. If you discover a security vulnerability, please follow these steps:

### Reporting Process

1. **DO NOT** open a public GitHub issue, discussion or pull request for security vulnerabilities
2. **DO** report the vulnerability privately through GitHub's [private vulnerability reporting form](https://github.com/shellmann/nextcloud-moviedb/security/advisories/new) (Security tab → "Report a vulnerability"). Only the maintainer can see the report.

3. **Include the following information**:
   - Description of the vulnerability
   - Steps to reproduce the issue
   - Affected MovieDB and Nextcloud versions
   - Potential impact
   - Suggested fix (if you have one)

### What to Expect

- **Acknowledgment**: I will try to acknowledge receipt of your report when I can
- **Assessment**: I will assess the vulnerability and determine its severity when possible
- **Updates**: I will try to keep you informed about progress in the advisory
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

- **TMDB API Key**: A personal TMDB API key is stored in Nextcloud's user settings, an instance-wide key set by an admin in the app config. Both are stored as sensitive values, so Nextcloud encrypts them and hides them from config listings. All TMDB requests go through the server, so the key never reaches the browser
- **Data Privacy**: Movie data is private to each user by default. Shared libraries allow users to intentionally share their collection with specific other Nextcloud users — access is controlled by the library owner via role assignments (viewer/editor)
- **XSS Protection**: All user input is sanitized to prevent cross-site scripting
- **SQL Injection**: Nextcloud's QueryBuilder is used to prevent SQL injection attacks
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

- I follow responsible disclosure practices
- Security advisories will be published on [GitHub Security Advisories](https://github.com/shellmann/nextcloud-moviedb/security/advisories)
- Critical vulnerabilities will be announced in the release notes
- I will credit security researchers (unless anonymity is requested)

## Questions

If you have questions about this security policy, please ask in [Discussions](https://github.com/shellmann/nextcloud-moviedb/discussions). Don't include details of a vulnerability there, since discussions are public.

Thank you for helping keep MovieDB and its users safe!
