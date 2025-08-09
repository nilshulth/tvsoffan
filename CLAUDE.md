# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

See README.md for full project details, goals, and feature specifications.

**Quick summary**: tvsoffan is a social movie/TV tracking app focusing on low friction logging and high sharing joy ("låg friktion, hög delningsglädje").

## Development Commands

**Setup:**
1. Copy `.env.example` to `.env` and configure database settings
2. Create database: `mysql -u root -p < schema.sql`
3. Install dependencies: `composer install`

**Run locally:**
```bash
php -S localhost:8000 -t public/
```

**Database:**
- Schema file: `schema.sql`
- Connection via PDO in `src/Database.php`

## Architecture Notes

See README.md for the complete tech stack. Key points for development:

- **Simplicity constraints**: No build step, no queues/workers, no microservices
- **Database**: Use PDO with UTF-8 (`utf8mb4_unicode_ci`) for all MariaDB operations
- **Frontend**: CDN-only assets (Tailwind CSS, Alpine.js), no build process
- **External APIs**: TMDB integration is core, JustWatch planned for v0.3