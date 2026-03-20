# Project scaffold templates

When you run **`bin/semitexa init`** or **`bin/semitexa init --only-docs --force`**, the framework writes these files from templates in **resources/init/** (inside this package):

| Written to project | Template in core |
|--------------------|------------------|
| `AI_ENTRY.md` | `resources/init/AI_ENTRY.md` |
| `README.md` | `resources/init/README.md` |
| `docs/AI_CONTEXT.md` | `resources/init/docs/AI_CONTEXT.md` |
| `docker-compose.yml` | `resources/init/docker-compose.yml` (app only; no RabbitMQ by default) |
| `docker-compose.rabbitmq.yml` | `resources/init/docker-compose.rabbitmq.yml` (optional overlay when EVENTS_ASYNC=1) |
| `server.php`, `.env.example`, `Dockerfile`, `phpunit.xml.dist`, `bin/semitexa`, `.gitignore`, `public/.htaccess` | `resources/init/<filename>` |

**Important:** Any change to the content of these files in the project root will be overwritten the next time you run `semitexa init` or `semitexa init --only-docs --force`. To change what gets generated, edit the templates in **resources/init/** in the semitexa/core package (or in your fork) and then re-run init.

The templates for the project documentation entry points are:

- [resources/init/README.md](../../resources/init/README.md)
- [resources/init/docs/AI_CONTEXT.md](../../resources/init/docs/AI_CONTEXT.md)

This folder keeps reference copies for scaffold-related docs.
