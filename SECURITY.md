# Security Policy

## Supported Versions

| Version | Supported |
| --- | --- |
| 1.x | Yes |

## Reporting a Vulnerability

Report suspected security vulnerabilities through the project issue queue using
Drupal.org security reporting guidance when available. Do not include secrets,
private URLs, or exploit payloads in public issues.

## Remediation Timeframes

| Severity | Target Patch Release |
| --- | --- |
| Critical | Within 24 hours |
| High | Within 7 days |
| Medium | Within 30 days |
| Low | Within 90 days |

## Dependency Monitoring

Third-party dependency risk is reviewed with:

- `composer audit` during release preparation and CI where available.
- Drupal Security Advisories for Drupal core and contributed dependencies.
- GuzzleHTTP and Symfony security advisories for outbound HTTP and HTML parsing
  dependencies.

## Deployment Notes

Do not expose VCS metadata from the web root in production. Keep `.git`
directories out of deployed artifacts where possible, or configure the web
server to deny access to dotfiles and dot-directories.
