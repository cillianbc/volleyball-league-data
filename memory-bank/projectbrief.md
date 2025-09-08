# Project Brief: Volleyball League Import Plugin

## Overview
This project involves developing and updating a WordPress plugin named "Volleyball League Import" that manages volleyball team data. The plugin creates a custom database table to store team information, provides REST API endpoints for importing and retrieving data, and integrates with external data sources for portability.

## Core Requirements
- **Database Management**: Create and maintain a custom WordPress database table (`wp_volleyball_teams`) to store team data including team ID, name, league, position, ranking points, logo URL, stats (matches, sets, points, results breakdown), penalty, and timestamps.
- **REST API Endpoints**:
  - POST `/volleyball/v1/import-teams`: Import or update team data (requires edit_posts permission).
  - GET `/volleyball/v1/teams/{league}`: Retrieve teams for a specific league from the latest import (public access).
- **Data Flow Update**: Originally, data was logged directly to WordPress database via n8n. Now, shift to n8n → GitHub (as intermediary API endpoint storing data as files, e.g., JSON) → WordPress plugin fetches from GitHub and inserts/updates DB. This makes the solution portable across WordPress sites without direct n8n-WP integration.
- **Portability**: The plugin should be simple and self-contained, using GitHub's REST API for data retrieval. Authentication via personal access token (stored securely in WP options). No complex dependencies.

## Goals
- Ensure data import is idempotent (update if exists for the day, else insert).
- Sanitize and validate incoming data.
- Support latest data retrieval for display (e.g., in Bricks Builder).
- Maintain backward compatibility with existing table structure.
- Document integration with n8n for GitHub updates (e.g., webhook or scheduled pushes to repo files).

## Scope
- Update existing plugin file: `volleyball-import.php`.
- No frontend UI; focus on backend API and DB operations.
- Assume JSON data format from GitHub matches current plugin expectations (e.g., array of teams with keys like teamId, teamName, etc.).
- Future expansions: Error handling, logging, cron-based auto-import.

## Non-Goals
- Implementing n8n workflows (handled externally).
- GitHub repo setup (assumed existing).
- Advanced security beyond WP nonces and sanitization.