# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

See README.md for full project details, goals, and feature specifications.

**Quick summary**: tvsoffan is a social movie/TV tracking app focusing on low friction logging and high sharing joy ("låg friktion, hög delningsglädje").

## Development Commands

This is an early-stage project. When actual code is implemented, add development commands here:
- How to set up local environment
- How to run the application (`php -S` for local dev)
- How to run tests
- How to lint/format code

## Architecture Notes

See README.md for the complete tech stack. Key points for development:

- **Simplicity constraints**: No build step, no queues/workers, no microservices
- **Database**: Use PDO with UTF-8 (`utf8mb4_unicode_ci`) for all MariaDB operations
- **Frontend**: CDN-only assets (Tailwind CSS, Alpine.js), no build process
- **External APIs**: TMDB integration is core, JustWatch planned for v0.3