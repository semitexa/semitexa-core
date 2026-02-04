# Adding new pages and routes

**New routes (pages, endpoints) in Semitexa are added only via modules.**  
Request/Handler classes in the project folder `src/` (namespace `App\`) are **not discovered** by the framework — do not add them there for routes. Put all new pages and endpoints in **modules** (`src/modules/`, `packages/`, or installed packages in `vendor/`).

---

## Step-by-step: create a new module and add a route

1. **Create the module directory**  
   Example: `src/modules/Website/` (or `Api`, `Blog`, etc.).

2. **Add `composer.json` inside the module**  
   So the framework recognises it as a Semitexa module and registers its autoload:

   ```json
   {
     "name": "semitexa/module-website",
     "type": "semitexa-module",
     "autoload": {
       "psr-4": {
         "Semitexa\\Modules\\Website\\": "src/"
       }
     }
   }
   ```

   Run `composer dump-autoload` in the **project root** after adding or changing module `composer.json`.

3. **Create Request and Handler in the module namespace**  
   Use namespace `Semitexa\Modules\{ModuleName}\...`, e.g. `Semitexa\Modules\Website\Application\Request\HomeRequest` and `Semitexa\Modules\Website\Application\Handler\HomeHandler`.

   **Example Request** — e.g. `src/modules/Website/src/Application/Request/HomeRequest.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Semitexa\Modules\Website\Application\Request;

   use Semitexa\Core\Attributes\AsRequest;
   use Semitexa\Core\Contract\RequestInterface;

   #[AsRequest(
       doc: 'docs/attributes/AsRequest.md',
       path: '/',
       methods: ['GET']
   )]
   class HomeRequest implements RequestInterface
   {
   }
   ```

   **Example Handler** — e.g. `src/modules/Website/src/Application/Handler/HomeHandler.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Semitexa\Modules\Website\Application\Handler;

   use Semitexa\Core\Attributes\AsRequestHandler;
   use Semitexa\Core\Contract\RequestInterface;
   use Semitexa\Core\Contract\ResponseInterface;
   use Semitexa\Core\Response;

   #[AsRequestHandler(
       doc: 'docs/attributes/AsRequestHandler.md',
       for: HomeRequest::class
   )]
   class HomeHandler
   {
       public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
       {
           return Response::json(['message' => 'Hello from Website module']);
       }
   }
   ```

   Use the **recommended** layout from **vendor/semitexa/docs/AI_REFERENCE.md** → **Module Structure** (Standard Module Layout): `Application/Input/` (Request DTOs), `Application/Output/` (Response DTOs), `Application/Handler/Request/` (HTTP handlers), `Application/View/templates/` (Twig). Do not rely only on core/docs for folder names — read AI_REFERENCE first. The class must live under the **module namespace** (`Semitexa\Modules\Website\...`) and the module must have a valid `composer.json` with `"type": "semitexa-module"` and PSR-4 autoload.

   The example above returns JSON. **For HTML pages** use a Response DTO with a Twig template — see the section **"Responses: JSON and HTML pages"** below (or AI_REFERENCE / guides in semitexa/docs).

4. **Reload**  
   Restart the app (e.g. `bin/semitexa server:stop` then `bin/semitexa server:start`) or ensure your runtime picks up the new classes; the framework will discover the new Request/Handler from the module.

---

## Responses: JSON and HTML pages

The step-by-step example above uses `Response::json([...])` — suitable for API endpoints. For **HTML pages** use **only** the **semitexa/core-frontend** package (Twig, layouts). **Do not implement your own Twig renderer in the project** — install core-frontend and use its patterns.

**Steps for HTML pages:**

1. **Install the package:** `composer require semitexa/core-frontend` (or the actual package name in your repository).
2. Create a Response class with the attribute `#[AsResponse(template: 'path/to/file.html.twig')]`.
3. Store templates in the module under `Application/View/templates/` (or your project’s convention).
4. The Handler fills the response context and returns the Response DTO; the framework uses core-frontend’s LayoutRenderer to render the Twig template.

**Recommended stack:** for HTML apps use semitexa/core + semitexa/core-frontend only — see `vendor/semitexa/docs/RECOMMENDED_STACK.md` when semitexa/docs is installed.

**Detailed docs:** in the **semitexa/docs** package — sections on Request/Response/Handler and Twig/templates. When installed: `vendor/semitexa/docs/AI_REFERENCE.md`, `vendor/semitexa/docs/guides/CONVENTIONS.md`, `vendor/semitexa/docs/guides/EXAMPLES.md`. Do not put raw HTML in the Handler and do not create a custom renderer — use Response DTO + core-frontend.

---

## Where to put Request/Handler

| Location | Discovered for routes? |
|----------|-------------------------|
| **Modules:** `src/modules/{ModuleName}/` (with `composer.json` `type: semitexa-module`) | Yes |
| **Packages:** project `packages/` (Semitexa packages with `composer.json`) | Yes |
| **Vendor:** installed packages (e.g. `vendor/semitexa/...`) | Yes |
| **Project `src/Request/`, `src/Handler/` (namespace `App\`) | **No** — not scanned for routes |

Place **all new routes** in a module (existing or new) under `src/modules/`, in `packages/`, or in an installed package. Do **not** add Request/Handler in `src/Request/` or `src/Handler/` in the project root for new pages or endpoints.

---

## How discovery works (architecture)

- **ModuleRegistry** finds modules in: `src/modules/`, project `packages/`, and `vendor/` (packages with `type: semitexa-module` or under `vendor/semitexa/`).
- **IntelligentAutoloader** and **AttributeDiscovery** load and scan only classes from those module namespaces (e.g. `Semitexa\Modules\*`, package namespaces). They do **not** scan the project `App\` namespace under `src/` for routes.
- So to add new routes you must have a **module** with a proper `composer.json` and PSR-4 mapping (e.g. `Semitexa\Modules\Website\` → `src/`). Adding `App\Request\*` / `App\Handler\*` in project `src/` is not a supported way to register routes.

---

## Common mistakes / FAQ

**Why don’t my Request/Handler in `src/Request/` or `src/Handler/` work (404)?**  
Because route discovery only uses **modules**. Classes in the project `src/` with namespace `App\` are not scanned for `#[AsRequest]` / `#[AsRequestHandler]`. Create a module in `src/modules/` with a `composer.json` (`"type": "semitexa-module"` and PSR-4 autoload) and put your Request/Handler there. See the step-by-step above.

**Can I patch `IntelligentAutoloader` or `AttributeDiscovery` to scan `App\`?**  
Do not patch vendor. The supported way to add routes is via modules; changing framework discovery to scan `App\` would break the intended architecture (everything route-related lives in modules).

**A future project check** could warn if classes with `#[AsRequest]` or `#[AsRequestHandler]` are found in project `src/Request/` or `src/Handler/` (namespace `App\`), and suggest moving them into a module (`src/modules/`).

---

## Summary

- **New pages/routes = only via modules** (`src/modules/`, `packages/`, or `vendor/`).
- **Never** add new routes as `App\Request\*` / `App\Handler\*` in project `src/Request/` or `src/Handler/`.
- Each module: directory, `composer.json` with `"type": "semitexa-module"` and PSR-4 (e.g. `Semitexa\Modules\Website\` → `src/`), then Request/Handler classes with `#[AsRequest]` and `#[AsRequestHandler]` in that namespace.
