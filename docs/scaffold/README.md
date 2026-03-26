# Project scaffold templates

When you run **`bin/semitexa init`** or **`bin/semitexa init --only-docs --force`**, the **`init` command from semitexa/ultimate** copies canonical scaffold assets from **semitexa/ultimate**.

| Written to project | Canonical asset in semitexa/ultimate |
|--------------------|--------------------------------------|
| `AI_ENTRY.md` | `AI_ENTRY.md` |
| `README.md` | `README.md` |
| `docs/AI_CONTEXT.md` | `docs/AI_CONTEXT.md` |
| `docker-compose.yml` | `docker-compose.yml` |
| `docker-compose.rabbitmq.yml` | `docker-compose.rabbitmq.yml` |
| `server.php`, `.env.example`, `Dockerfile`, `phpunit.xml.dist`, `bin/semitexa`, `.gitignore`, `public/.htaccess` | matching file in `semitexa/ultimate/` |

**Important:** Any change to the content of these files in the project root will be overwritten the next time you run `semitexa init` or `semitexa init --only-docs --force`. To change what gets generated, edit the scaffold assets in **semitexa/ultimate** and then re-run init.

The scaffold sources for the project documentation entry points are:

- [README.md](https://github.com/semitexa/semitexa-ultimate/blob/main/README.md)
- [docs/AI_CONTEXT.md](https://github.com/semitexa/semitexa-ultimate/blob/main/docs/AI_CONTEXT.md)

This folder keeps reference copies for scaffold-related docs.
