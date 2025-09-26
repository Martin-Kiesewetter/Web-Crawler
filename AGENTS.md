# Repository Guidelines

## Project Structure & Module Organization
The codebase is intentionally lean. `index.php` bootstraps the crawl by instantiating `webanalyse` and handing off the crawl identifier. Core crawling logic lives in `webanalyse.php`, which houses HTTP fetching, link extraction, and database persistence. Use `setnew.php` to reset seed data inside the `screaming_frog` schema before a rerun. Keep new helpers in their own PHP files under this root so the autoload includes stay predictable; group SQL migrations or fixtures under a `database/` folder if you add them. IDE settings reside in `.idea/`.

## Build, Test, and Development Commands
Run the project through Apache in XAMPP or start the PHP built-in server with `php -S localhost:8080 index.php` from this directory. Validate syntax quickly via `php -l webanalyse.php` (repeat for any new file). When iterating on crawl logic, truncate runtime tables with `php setnew.php` to restore the baseline dataset.

## Coding Style & Naming Conventions
Follow PSR-12 style cues already in use: 4-space indentation, brace-on-new-line for functions, and `declare(strict_types=1);` at the top of entry scripts. Favour descriptive camelCase for methods (`getMultipleWebsites`) and snake_case only for direct SQL field names. Maintain `mysqli` usage for consistency, and gate new configuration through constants or clearly named environment variables.

## Testing Guidelines
There is no automated suite yet; treat each crawl as an integration test. After code changes, run `php setnew.php` followed by a crawl and confirm that `crawl`, `urls`, and `links` tables reflect the expected row counts. Log anomalies with `error_log()` while developing, and remove or downgrade to structured responses before merging.

## Commit & Pull Request Guidelines
Author commit messages in the present tense with a concise summary (`Add link grouping for external URLs`). Group related SQL adjustments with their PHP changes in the same commit. For pull requests, include: a short context paragraph, reproduction steps, screenshots of key output tables when behaviour changes, and any follow-up tasks. Link tracking tickets or issues so downstream agents can trace decisions.

## Security & Configuration Notes
Database credentials are currently hard-coded for local XAMPP usage. If you introduce environment-based configuration, document expected `.env` keys and ensure credentials are excluded from version control. Never commit production connection details or raw crawl exports.
