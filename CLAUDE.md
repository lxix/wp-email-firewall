# lxix Email Firewall

WordPress plugin (slug and text domain: `lxix-email-firewall`) that suppresses emails to blocked addresses and domains. It filters the recipients of every `wp_mail()` call, and handles form submissions (Elementor Pro, WPForms, Contact Form 7, Fluent Forms) and WooCommerce emails. License: Apache-2.0.

It was called WP Email Firewall (`wp-email-firewall`) until WordPress.org submission: WordPress.org doesn't allow "WP" in plugin names and slugs. The WordPress.org review then found "Email Firewall" (`email-firewall`) too generic, and asked for a distinctive term at the start of the name: `lxix` is the author's WordPress.org username. Keep this name in the plugin header, the readme and the UI; the slug can't change after the approval. The GitHub repository and the Docker project kept the old name. The `wpef_` prefix stayed too, so the settings and the log of earlier installs carry over.

## Layout

- `lxix-email-firewall.php`: the whole plugin, a single class (`WPEF_Plugin`)
- `uninstall.php`: removes the options, the log table and the cron event (on every site of a multisite)
- `readme.txt`: the WordPress.org readme. Its `Stable tag` always equals the `Version` header, and every release gets a changelog entry.
- `languages/`: the hu_HU `.po` source (the source strings are English). It stays out of the zip: once the plugin is approved, it's imported into translate.wordpress.org, which delivers it as a language pack.
- `build.sh`: builds the release zip into `.out/`
- `.github/workflows/release.yml`: builds and publishes a release when a version tag is pushed
- `test-env/`: Docker test environment

## Conventions

- The code is self-documenting. Class, method and variable names say what the code does. Comments only explain *why*: WordPress or third-party plugin quirks, ordering constraints and non-obvious decisions. They never restate the code. If code needs a "what" comment, give it a better name or extract a small method instead.
- Prefix everything global: `wpef_` for options, hooks, cron events, nonces and the settings group; `wpef-` for HTML ids and CSS classes; `WPEF_` for classes. The log table is `{$wpdb->prefix}wpef_log`.
- A new setting is registered in `registerSettings()`. Every option, internal ones like `wpef_db_version` included, is deleted in `uninstall.php`.
- A removed option stays in the `uninstall.php` list, and an upgrade step in `install()` deletes it (bump `DB_VERSION` for it).
- Stay compatible with PHP 7.4 and WordPress 6.0 (see the plugin header). PHP 8 syntax is not allowed: no named arguments, `match`, union types, constructor promotion or nullsafe operator.
- `declare(strict_types=1)`, 4-space indentation, UTF-8, LF line endings (enforced by `.gitattributes`).
- Every user-facing string goes through `__()` / `esc_html__()` / `esc_attr__()` with the `lxix-email-firewall` text domain. When you add or change a string, update the hu_HU `.po` file with a real Hungarian translation ("block" is "tilt" (tiltott, tiltás), and "email" is spelled "e-mail", "e-mail-cím"), then compile it into the test environment with `test-env/wp i18n make-mo wp-content/plugins/lxix-email-firewall/languages wp-content/languages/plugins` (`setup.sh` does this too).
- Don't call `load_plugin_textdomain()` and don't ship translation files: WordPress loads the language packs from translate.wordpress.org by itself, and the WordPress.org review asked for both to be removed.
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
test-env/wp --exec='WP_CLI::add_wp_hook("locale", static fn () => "hu_HU");' eval 'echo __("Save settings", "lxix-email-firewall");'

# Syntax check on the oldest supported PHP version
docker run --rm -v "$PWD":/app:ro -w /app php:7.4-cli php -l lxix-email-firewall.php

# Plugin Check (the plugin-check plugin), on the files that go into the zip
test-env/wp plugin check lxix-email-firewall --exclude-directories=languages,test-env,.github,.wordpress-org,.idea,.out --exclude-files=CLAUDE.md,build.sh,.gitattributes,.gitignore
```

## Build

`./build.sh` creates `.out/lxix-email-firewall.zip`, with the plugin in an `lxix-email-firewall/` folder at its root. The unzipped folder stays in `.out/lxix-email-firewall/`. It needs `tar` and `zip` on the host. The zip leaves out `languages/` and the development files: `.out/`, `test-env/`, `.wordpress-org/`, `CLAUDE.md`, `build.sh` and the git files (`.github/` included). When you add a development-only file or directory, add it to the exclude list in `build.sh` and to the Plugin Check command above too.

## Release

1. Raise the `Version` header and the `Stable tag`, and add the changelog entry to `readme.txt`.
2. Commit, then tag the commit with the version (`v1.2.0`) and push the tag.

The tag starts the release workflow. It fails if the tag, the `Version` header and the `Stable tag` differ, or if the changelog has no entry for the version. Then it runs `build.sh`, the PHP 7.4 syntax check and Plugin Check (warnings fail it too) on `.out/lxix-email-firewall/`. It deploys that folder to the WordPress.org SVN repository (trunk and the version tag), and creates the GitHub release with the zip and the changelog entry as its notes. If the workflow fails, delete the tag locally and on GitHub, fix the problem, then tag the fixed commit and push the tag again. A re-run is safe: it skips a version that is already on WordPress.org and a GitHub release that already exists.

The WordPress.org deploy only runs once the `SVN_USERNAME` repository variable and the `SVN_PASSWORD` secret (the SVN password from the WordPress.org profile, not the account password) are set in the GitHub repository settings. Banners, icons and screenshots for the plugin page go in `.wordpress-org/`: the deploy copies them to the SVN `assets` directory.
