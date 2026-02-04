# Running Semitexa applications

The **only supported way** to run a Semitexa application is via **Docker**.

- **Start:** `bin/semitexa server:start` (runs `docker compose up -d`)
- **Stop:** `bin/semitexa server:stop` (runs `docker compose down`)
- **Logs:** `docker compose logs -f`

The application runs `php server.php` inside the container; the Swoole server listens on port 9501 by default (configurable via `.env` `SWOOLE_PORT`). Do not run `php server.php` on the host as the primary way to run the app.

After `semitexa init` (or first `composer install` with semitexa/core), the project includes a minimal `docker-compose.yml` and `Dockerfile` so that `server:start` works without extra setup.

If you see "docker-compose.yml not found", run `semitexa init` to generate the project structure including `docker-compose.yml`, or add it manually.
