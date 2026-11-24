
# WorkSync

WorkSync is a PHP and MySQL marketplace application. It includes account registration, product listings, chat, orders, and a simulated payment flow.

## Run with Docker

The repository includes a Dockerfile for Apache and PHP 8.2. Set `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` for a MySQL database. On container startup, the app imports the idempotent schema from `worksync_full.sql` and starts Apache on the port supplied by the host (8080 locally).

For email verification and password resets, configure `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`, `MAIL_NAME`, and `SITE_URL`. Keep credential values in the hosting provider's secret variables; do not commit `config.php` or `mail_config.php`.

The payment screen is a simulator and does not process real payments. Uploaded product, avatar, and review images are written under `assets/`; production hosting should mount persistent storage there or use object storage.
