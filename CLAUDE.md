# Email Firewall

WordPress plugin (slug and text domain: `email-firewall`) that suppresses emails to blocked addresses and domains. It filters the recipients of every `wp_mail()` call, and handles form submissions (Elementor Pro, WPForms, Contact Form 7, Fluent Forms) and WooCommerce emails. License: Apache-2.0.

It was called WP Email Firewall (`wp-email-firewall`) until WordPress.org submission: WordPress.org doesn't allow "WP" in plugin names and slugs. The GitHub repository and the Docker project kept the old name. The `wpef_` prefix stayed too, so the settings and the log of earlier installs carry over.

## Layout

- `email-firewall.php`: the whole plugin, a single class (`WPEF_Plugin`)
- `uninstall.php`: removes the options, the log table and the cron event (on every site of a multisite)
- `readme.txt`: the WordPress.org readme. Its `Stable tag` always equals the `Version` header, and every release gets a changelog entry.
- `languages/`: the hu_HU `.po` source and its compiled `.mo` file (the source strings are English)
- `build.sh`: builds the release zip into `.out/`
- `test-env/`: Docker test environment

## Conventions

- The code is self-documenting. Class, method and variable names say what the code does. Comments only explain *why*: WordPress or third-party plugin quirks, ordering constraints and non-obvious decisions. They never restate the code. If code needs a "what" comment, give it a better name or extract a small method instead.
- Prefix everything global: `wpef_` for options, hooks, cron events, nonces and the settings group; `wpef-` for HTML ids and CSS classes; `WPEF_` for classes. The log table is `{$wpdb->prefix}wpef_log`.
- A new setting is registered in `registerSettings()`. Every option, internal ones like `wpef_db_version` included, is deleted in `uninstall.php`.
- A removed option stays in the `uninstall.php` list, and an upgrade step in `install()` deletes it (bump `DB_VERSION` for it).
- Stay compatible with PHP 7.4 and WordPress 6.0 (see the plugin header). PHP 8 syntax is not allowed: no named arguments, `match`, union types, constructor promotion or nullsafe operator.
- `declare(strict_types=1)`, 4-space indentation, UTF-8, LF line endings (enforced by `.gitattributes`).
- Every user-facing string goes through `__()` / `esc_html__()` / `esc_attr__()` with the `email-firewall` text domain. When you add or change a string, update the hu_HU `.po` file with a real Hungarian translation ("block" is "tilt" (tiltott, tiltás), and "email" is spelled "e-mail", "e-mail-cím"), then recompile the `.mo` file with `./build.sh`.
- Plugin Check must stay clean. Fix a finding in the code; suppress it only when the code is right, with a `phpcs:ignore` that names the sniff and gives the reason after `--`. SQL strings name the log table as `{$wpdb->prefix}wpef_log`, because the coding standards accept no other interpolated variable in a query.

## Testing: Docker only

The plugin may only be run and tested in the Docker environment in `test-env/`, which has WordPress, MariaDB, Mailpit and WP-CLI. Never install PHP, WordPress or a database on the host, and never run the plugin code outside the containers.

The repository is mounted read-only as the plugin directory, so code changes take effect without a restart. A bind mount keeps pointing at the directory that existed when the container started: if a mounted directory is deleted and re-created (e.g. `test-env/mu-plugins` by a git checkout), recreate the containers with `docker compose -f test-env/docker-compose.yml up -d --force-recreate`.

```bash
test-env/setup.sh                            # start, install WordPress, activate the plugin
test-env/setup.sh woocommerce wpforms-lite contact-form-7 fluentform plugin-check   # the same, plus these wordpress.org plugins
test-env/wp <command>                        # WP-CLI inside the container
docker compose -f test-env/docker-compose.yml down -v   # stop and wipe everything
```

- WordPress: http://localhost:8080/wp-admin/ (admin / admin). The ports can be changed with `WP_PORT` and `MAILPIT_PORT`.
- Every email is delivered to Mailpit (`test-env/mu-plugins/mailpit.php`). The UI is at http://localhost:8025. List the messages with `curl -s localhost:8025/api/v1/messages` and delete them with `curl -s -X DELETE localhost:8025/api/v1/messages`.
- Elementor forms need Elementor Pro, a paid plugin that can't be installed from wordpress.org.
- WP-CLI commands, including `wp db query`, load the active plugin, and loading it recreates its table and options. Deactivate the plugin before you test `uninstall.php`.

Examples:

```bash
test-env/wp option update wpef_blocked_domains spam.test
test-env/wp eval 'var_dump(wp_mail("a@spam.test", "Subject", "Body"));'
test-env/wp db query "SELECT * FROM wp_wpef_log"

# Hungarian strings (the locale has to be set before WordPress loads)
test-env/wp --exec='WP_CLI::add_wp_hook("locale", static fn () => "hu_HU");' eval 'echo __("Save settings", "email-firewall");'

# Syntax check on the oldest supported PHP version
docker run --rm -v "$PWD":/app:ro -w /app php:7.4-cli php -l email-firewall.php

# Plugin Check (the plugin-check plugin), on the files that go into the zip
test-env/wp plugin check email-firewall --exclude-directories=test-env,.idea,.out --exclude-files=CLAUDE.md,build.sh,.gitattributes,.gitignore
```

## Build

`./build.sh` compiles the `.mo` files and creates `.out/email-firewall.zip`, with the plugin in an `email-firewall/` folder at its root. It needs `msgfmt`, `tar` and `zip` on the host. The zip leaves out the development files: `.out/`, `test-env/`, `CLAUDE.md`, `build.sh` and the git files. When you add a development-only file or directory, add it to the exclude list in `build.sh` and to the Plugin Check command above too.
