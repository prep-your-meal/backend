# 🥗 PrepYourMeal API

A headless backend providing the core business logic for PrepYourMeal. It handles intelligent meal plan scheduling and automated shopping list aggregation.

## ✨ Features

- **Smart Meal Planning:** Automatically generates 7-day meal plans, ensuring recipes are not repeated within a 30-day window.
- **Aggregated Shopping Lists:** Dynamically calculates and categorizes required ingredients based on the active meal plan and user-defined portion sizes.
- **GitOps Recipe Management:** Synchronizes markdown recipes directly from a GitHub repository via secure webhooks.
- **Secure Authentication:** Stateless API token management via Laravel Sanctum, integrated with Laravel Socialite for OAuth2 (Google/GitHub) logins.
- **Interactive Documentation:** Fully documented OpenAPI 3.0 (Swagger) interface for easy frontend integration and testing.

## 🛠 Tech Stack

- **Framework:** Laravel [PHP 8.2+]
- **Database:** MariaDB / MySQL
- **Environment:** Docker & Laravel Sail
- **Authentication:** Laravel Sanctum & Laravel Socialite
- **API Docs:** L5-Swagger

---

## 🚀 Local Development Setup

This project uses Laravel Sail and is optimized for **Docker**.

### 1. Prerequisites

Ensure you have the following installed on your machine:

- [Docker](https://www.docker.com/) [and Docker Compose]
- Git

### 2. Clone the Repository

```bash
git clone [https://github.com/YOUR-GITHUB-NAME/prep-your-meal-api.git](https://github.com/YOUR-GITHUB-NAME/prep-your-meal-api.git)
cd prep-your-meal-api

```

### 3. Install Composer Dependencies

Since you might not have PHP installed locally, use a small temporary container to install the vendor dependencies:

```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php83-composer:latest \
  composer install --ignore-platform-reqs

```

Note: Running `composer install` will automatically configure the local Git hooks for this project.

### 4. Environment Configuration

Copy the environment template and adjust the necessary variables:

```bash
cp .env.example .env

```

Make sure to configure the OAuth and Webhook variables in your `.env` file (see the Configuration section below).

### 5. Start the Sail Containers

```bash
./vendor/bin/sail up -d
```

### 6. Initialize Application Setup

Generate the application key and run all database migrations:

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

### 7. Create a Local Test Admin

To test the API locally, create a default user via Laravel Tinker:

```bash
./vendor/bin/sail artisan tinker
```

Inside the Tinker console, run:

```php
User::create(['name' => 'Admin', 'email' => 'admin@test.de', 'password' => Hash::make('password123')]);
```

Type `exit` to leave Tinker.

---

## 📖 API Documentation (Swagger)

The API is fully documented using Swagger. To generate or update the documentation, run:

```bash
./vendor/bin/sail artisan l5-swagger:generate
```

- **Access the UI:** `http://localhost/api/documentation`

- **Authentication:** Use the `/api/auth/login` endpoint to get a Sanctum token. Copy the token and paste it into the "Authorize" modal at the top of the Swagger UI to access protected routes.

---

## ⚙️ Key Environment Variables (`.env`)

For all features to work, ensure the following keys are set in your `.env` file:

```dotenv
# Frontend URL (Used for OAuth redirects)
FRONTEND_URL=http://localhost:5173

# GitHub OAuth
GITHUB_CLIENT_ID=your_github_client_id
GITHUB_CLIENT_SECRET=your_github_client_secret
GITHUB_REDIRECT_URI=http://localhost/api/auth/github/callback

# Google OAuth
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://localhost/api/auth/google/callback

# GitHub Recipe Sync Webhook
GITHUB_REPO=your_github_username/your_recipe_repo
GITHUB_BRANCH=main
GITHUB_TOKEN=your_personal_access_token_for_reading_the_repo
```

---

## 🪝 Code Quality & Git Hooks

This project enforces strict code quality and commit standards using native Git hooks and Continuous Integration. The hooks are automatically configured when you run `composer install`.

### Pre-Commit Checks

Before a commit is created, the following automated checks run locally:

1. **Laravel Pint (Linter):** Checks the code style against Laravel standards. If this fails, run `./vendor/bin/sail pint` to automatically fix formatting issues, stage the changed files, and try committing again. (Requires Sail containers to be running).

2. **Larastan / PHPStan (Static Analysis):** Scans the code for logical errors and type inconsistencies (currently checking on Level 5). If this fails, review the reported errors and fix them. You can run it manually via `./vendor/bin/sail bin phpstan analyse`. (Requires Sail containers to be running).

3. **Composer Audit (Vulnerability Scanner):** Scans the `composer.lock` file for known security vulnerabilities (CVEs) in installed packages. If this fails, run `./vendor/bin/sail composer update` to patch the dependencies. (Requires Sail containers to be running).

4. **Gitleaks (Secret Scanner):** Scans the codebase for accidentally committed secrets (API keys, passwords) via a temporary Docker container.

### Continuous Integration (CI)

In addition to local hooks, our GitHub Action (`ci.yml`) acts as a gatekeeper. It automatically validates every Code-Push and Pull Request. If Pint flags unformatted code or Larastan detects an error, the pipeline will fail and highlight the exact issue directly in GitHub.

### Commit Message Standard (Conventional Commits)

Commit messages must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification:
`<type>[optional scope]: <description>`

**Examples:**

- `feat(auth): implement google login`

- `fix(shopping-list): correct amount aggregation logic`

- `chore(git): update readme with hook documentation`

---

## 🧪 Testing

The application uses PHPUnit/Pest for automated feature and unit testing. To run the test suite:

```bash
./vendor/bin/sail artisan test
```

## 🔒 Security

- All endpoints under `/api/plan` and `/api/shopping-list` are strictly protected by the `auth:sanctum` middleware.

- OAuth callbacks handle user creation completely stateless to support modern headless PWA architectures.

## 📄 License

This project is open-sourced software licensed under the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).
You are free to use, modify, and distribute this software, provided that you state changes and retain the original copyright notice. For more details, see the `LICENSE` file in the repository.
