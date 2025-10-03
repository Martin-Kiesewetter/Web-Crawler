# Repository Guidelines

## Project Structure & Module Organization
The codebase is intentionally lean. `index.php` bootstraps the crawl by instantiating `webanalyse` and handing off the crawl identifier. Core crawling logic lives in `webanalyse.php`, which houses HTTP fetching, link extraction, and database persistence. Use `setnew.php` to reset seed data inside the `screaming_frog` schema before a rerun. Keep new helpers in their own PHP files under this root so the autoload includes stay predictable; group SQL migrations or fixtures under a `database/` folder if you add them. IDE settings reside in `.idea/`.

## Build, Test, and Development Commands

### Docker Development
The project runs in Docker containers. Use these commands:

```bash
# Start containers
docker-compose up -d

# Stop containers
docker-compose down

# Rebuild containers
docker-compose up -d --build

# View logs
docker-compose logs -f php
```

### Running Tests
The project uses PHPUnit for automated testing:

```bash
# Run all tests (Unit + Integration)
docker-compose exec php sh -c "php /var/www/html/vendor/bin/phpunit /var/www/tests/"

# Or use the composer shortcut
docker-compose exec php composer test
```

**Test Structure:**
- `tests/Unit/` - Unit tests for individual components
- `tests/Integration/` - Integration tests for full crawl workflows
- All tests run in isolated database transactions

### Static Code Analysis
PHPStan is configured at Level 8 (strictest) to ensure type safety:

```bash
# Run PHPStan analysis
docker-compose exec php sh -c "php -d memory_limit=512M /var/www/html/vendor/bin/phpstan analyse -c /var/www/phpstan.neon"

# Or use the composer shortcut
docker-compose exec php composer phpstan
```

**PHPStan Configuration:**
- Level: 8 (maximum strictness)
- Analyzes: `src/` and `tests/`
- Excludes: `vendor/`
- Config file: `phpstan.neon`

All code must pass PHPStan Level 8 with zero errors before merging.

### Code Style Checking
PHP_CodeSniffer enforces PSR-12 coding standards:

```bash
# Check code style
docker-compose exec php composer phpcs

# Automatically fix code style issues
docker-compose exec php composer phpcbf
```

**PHPCS Configuration:**
- Standard: PSR-12
- Analyzes: `src/` and `tests/`
- Excludes: `vendor/`
- Auto-fix available via `phpcbf`

Run `phpcbf` before committing to automatically fix most style violations.

## Coding Style & Naming Conventions
Follow PSR-12 style cues already in use: 4-space indentation, brace-on-new-line for functions, and `declare(strict_types=1);` at the top of entry scripts. Favour descriptive camelCase for methods (`getMultipleWebsites`) and snake_case only for direct SQL field names. Maintain `mysqli` usage for consistency, and gate new configuration through constants or clearly named environment variables.

## Testing Guidelines

### Automated Testing
The project has a comprehensive test suite using PHPUnit:

- **Write tests first**: Follow TDD principles when adding new features
- **Unit tests** (`tests/Unit/`): Test individual classes and methods in isolation
- **Integration tests** (`tests/Integration/`): Test full crawl workflows with real HTTP requests
- **Database isolation**: Tests use transactions that roll back automatically
- **Coverage**: Aim for high test coverage on critical crawl logic

### Quality Gates
Before committing code, ensure:
1. All tests pass: `docker-compose exec php composer test`
2. PHPStan analysis passes: `docker-compose exec php composer phpstan`
3. Code style is correct: `docker-compose exec php composer phpcs`
4. Auto-fix style issues: `docker-compose exec php composer phpcbf`

**Pre-commit Checklist:**
- ✅ Tests pass
- ✅ PHPStan Level 8 with 0 errors
- ✅ PHPCS PSR-12 compliance (warnings acceptable)

### Manual Testing
For UI changes, manually test the crawler interface at http://localhost:8080. Verify:
- Job creation and status updates
- Page and link extraction accuracy
- Error handling for invalid URLs or network issues

## Commit & Pull Request Guidelines
Author commit messages in the present tense with a concise summary (`Add link grouping for external URLs`). Group related SQL adjustments with their PHP changes in the same commit. For pull requests, include: a short context paragraph, reproduction steps, screenshots of key output tables when behaviour changes, and any follow-up tasks. Link tracking tickets or issues so downstream agents can trace decisions.

## Security & Configuration Notes
Database credentials are currently hard-coded for local XAMPP usage. If you introduce environment-based configuration, document expected `.env` keys and ensure credentials are excluded from version control. Never commit production connection details or raw crawl exports.
