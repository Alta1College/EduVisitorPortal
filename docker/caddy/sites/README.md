# Extra Caddy sites (server-only)

Caddy (`docker-compose.caddy.yml`) also loads every `*.caddy` file in this folder. Use it for
hostnames that belong to one installation only, for example a certificate Caddy should obtain and
renew for another device. These files are not committed (`.gitignore`); create them on the server.

Example `fm.example.org.caddy`, which only obtains and renews a certificate:

```
fm.example.org {
	respond "Not found" 404
}
```

The certificate and key are then in the `caddy_data` volume under
`/data/caddy/certificates/acme-v02.api.letsencrypt.org-directory/<host>/`.
