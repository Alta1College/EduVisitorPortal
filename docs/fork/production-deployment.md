# Production deployment (Docker, single Linux server)

This is the Edu Visitor Portal way to run `docker-compose.prod.yml`, building the image from this
repository. It adds two things to upstream's guide (docs/deployment.md):

- **`app.env`:** settings the production compose file does not list, such as Microsoft Graph
  mail, Entra host sync and visitor defaults.
- **`docker-compose.caddy.yml`:** HTTPS with automatic Let's Encrypt certificates.

## 1. Server

Use Ubuntu Server LTS (24.04 or newer) with 2 vCPU, 4 GB RAM and about 60 GB disk. Then:

- Install Docker Engine from Docker's apt repository.
- Allow SSH with keys only.
- Turn on the firewall, with 80/443 open (and 22 only from admin networks).
- Turn on unattended security upgrades.

Docker publishes ports past the host firewall. With the Caddy file, only 80/443 are published.

The server must be able to reach:

- `login.microsoftonline.com` and `graph.microsoft.com` on 443;
- Let's Encrypt and Docker Hub;
- each site's label printer on TCP 9100, for example over a site VPN.

## 2. Code and image

```bash
git clone <repository> /opt/eduvp && cd /opt/eduvp
git checkout <release tag or commit>
docker build -f docker/app/Dockerfile -t eduvp:$(git rev-parse --short HEAD) .
```

## 3. Settings

There are two files, both readable by root and the deploy user only (`chmod 600`):

**`.env`** holds the values `docker-compose.prod.yml` names. These are read by Compose and
**take precedence**:

```
VISITORPORTAL_IMAGE=eduvp:<commit>
APP_KEY=base64:...            # docker run --rm eduvp:<commit> php artisan key:generate --show
APP_URL=https://visitors.example.org
APP_TIMEZONE=Australia/Perth
APP_LOCALE=en
DB_DATABASE=visitorportal
DB_USERNAME=visitorportal
DB_PASSWORD=<random>
DB_ROOT_PASSWORD=<random>
MAIL_MAILER=microsoft-graph    # or smtp with MAIL_HOST / MAIL_PORT / ...
MAIL_FROM_ADDRESS=visitors@example.org
MAIL_FROM_NAME="Example Visitors"
SSO_ENABLED=true               # and the other OIDC_* values (docs/fork/phase-10-...)
TRUSTED_PROXIES=*              # only with the Caddy file, where Caddy is the only way in
SITE_ADDRESS=visitors.example.org
```

**`app.env`** holds everything else the app reads (see `app.env.example`). A value here
**cannot** override one already set by `.env`.

## 4. Start

```bash
docker compose -f docker-compose.prod.yml -f docker-compose.caddy.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan visitorportal:install --create-admin
```

Before DNS points at the server, leave out the Caddy file and test on the internal address. The
app is then on `APP_PORT`, 8080 by default. Never expose that port to the internet.

Caddy also loads any `docker/caddy/sites/*.caddy` files on the server (not committed). Use them
for installation-specific hostnames, for example to have Caddy renew a certificate for another
device (docker/caddy/sites/README.md).

## 5. Updates

```bash
git fetch && git checkout <new tag or commit>
docker build -f docker/app/Dockerfile -t eduvp:$(git rev-parse --short HEAD) .
# set VISITORPORTAL_IMAGE in .env to the new tag, back up (below), then:
docker compose -f docker-compose.prod.yml -f docker-compose.caddy.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

## 6. Backups

Back up nightly and copy the backups off the server:

- **The database:**
  ```bash
  docker compose -f docker-compose.prod.yml exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' | gzip
  ```
- **The `app_storage` volume:** photos, signatures, branding and evidence.
- **`.env` and `app.env`:** these contain secrets, so store them encrypted.

Test a restore before go-live.
