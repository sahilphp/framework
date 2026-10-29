# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's [private vulnerability reporting][report]
(Security → Advisories → "Report a vulnerability") or by e-mail to
`idrisajohn19@gmail.com`.

[report]: https://github.com/sahilphp/framework/security/advisories/new

Include:

- affected version (`composer show sahilphp/framework`),
- a description of the issue and its impact,
- reproduction steps or a proof of concept,
- any suggested fix.

## What to expect

- Acknowledgement within **72 hours**.
- An assessment and (when confirmed) a fix plan within **7 days**.
- A patched release plus a GitHub Security Advisory; credit is given unless
  you ask to stay anonymous.
- Please allow us to ship a fix before publishing details.

## Scope

In scope: authentication/session handling, CSRF protection, encryption and
signing (`Sahil\Encryption`), SQL handling in the query builder and ORM,
file uploads/storage, template escaping, the HTTP kernel and middleware,
queue payload handling, and dependency vulnerabilities reachable through the
framework.

Out of scope: issues that require a compromised server or database, missing
headers in an application that disabled the middleware, and reports produced
solely by automated scanners without a demonstrated impact.

## Hardening checklist for applications

- `APP_DEBUG=false` and `APP_ENV=production` in production (validated at
  boot when strict checks are enabled).
- `APP_KEY` set and unique per installation (`php bin/sahil key:generate`).
- Point the web server at `public/` only; never at the project root.
- Serve over HTTPS and enable `secure` session cookies behind TLS.
- Run `composer audit` in CI and keep dependencies current.
- Set `queue.allowed_jobs` in `config/queue.php` for defence in depth.
