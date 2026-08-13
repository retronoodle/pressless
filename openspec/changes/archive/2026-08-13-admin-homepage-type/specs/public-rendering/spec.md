## ADDED Requirements

### Requirement: Homepage renders according to the effective homepage type

The system SHALL serve `GET /` by resolving the effective homepage type as: the saved `settings.homepage_type` override if not `NULL`, otherwise the active theme's `theme.json` `homepage_type`, otherwise `collection_list`. When the effective type is `collection_list`, the system SHALL render `home.twig` with all collections, unchanged from current behavior. When the effective type is `static_page`, the system SHALL render the entry referenced by `settings.homepage_page_id` the same way the public entry route renders it. If that entry no longer exists, the system SHALL fall back to rendering `collection_list` behavior instead of erroring.

#### Scenario: No override and no theme default

- **WHEN** a visitor requests `GET /` and neither an admin override nor the active theme declares a homepage type
- **THEN** the response is 200 and renders `home.twig` with all collections, matching today's behavior

#### Scenario: Theme declares a default homepage type

- **WHEN** a visitor requests `GET /`, no admin override is saved, and the active theme's `theme.json` declares `homepage_type: static_page` with a configured page
- **THEN** the response is 200 and renders the configured entry as the homepage

#### Scenario: Admin override takes precedence over theme default

- **WHEN** a visitor requests `GET /` and an admin override is saved that differs from the active theme's default
- **THEN** the response uses the admin override's homepage type, not the theme's default

#### Scenario: Configured static page has been deleted

- **WHEN** a visitor requests `GET /`, the effective homepage type is `static_page`, and the referenced entry no longer exists
- **THEN** the response is 200 and falls back to rendering `collection_list` behavior instead of erroring
