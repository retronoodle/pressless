## ADDED Requirements

### Requirement: Media upload

The system SHALL provide an authenticated admin endpoint that accepts a single file upload, validates its mime type against an allow-list (`image/jpeg`, `image/png`, `image/gif`, `image/webp`) and its size against a configured maximum, and on success stores the file and creates a row in the `media` table with `filename`, `mime_type`, `size_bytes`, `path`, and `uploaded_by`.

#### Scenario: Successful upload

- **WHEN** an authenticated admin uploads a JPEG under the size limit
- **THEN** the file is stored, a `media` row is created, and the response identifies the new media item

#### Scenario: Rejected mime type

- **WHEN** a file with a mime type outside the allow-list is uploaded
- **THEN** the upload is rejected with a clear error and no file or `media` row is created

#### Scenario: Rejected oversize file

- **WHEN** a file larger than the configured maximum size is uploaded
- **THEN** the upload is rejected with a clear error and no file or `media` row is created

#### Scenario: Mime type is verified server-side

- **WHEN** a file is uploaded with a client-reported mime type that does not match its actual content
- **THEN** the server-detected mime type is used for validation, not the client-reported one

### Requirement: Media library admin UI

The admin SHALL provide a media library screen listing uploaded files (thumbnail, filename, size, upload date) with an upload control, following the same controller/route/template pattern as the collection and entry admin screens.

#### Scenario: Library lists uploaded files

- **WHEN** an admin visits the media library screen after uploading two files
- **THEN** both files are listed with their filename, size, and a thumbnail preview

#### Scenario: Upload from the library screen

- **WHEN** an admin uploads a new file from the media library screen
- **THEN** the file appears in the list without a full page reload requirement being assumed (a standard form submit + redirect is sufficient)

### Requirement: Image transforms

For uploaded files with an image mime type, the system SHALL generate resized variants on demand for a fixed, configured set of named sizes (e.g. `thumbnail`, `medium`, `full`), using GD, and cache each generated variant to disk so it is not regenerated on subsequent requests.

#### Scenario: First request generates and caches a variant

- **WHEN** a named size is requested for an image for the first time
- **THEN** the system generates the resized variant, writes it to the transform cache, and returns it

#### Scenario: Subsequent request serves the cached variant

- **WHEN** a named size is requested for an image that already has a cached variant
- **THEN** the system serves the cached file without re-running the transform

#### Scenario: Unknown size is rejected

- **WHEN** a size key outside the configured named-size set is requested
- **THEN** the request is rejected rather than generating an arbitrary-dimension variant

#### Scenario: Non-image files are not transformed

- **WHEN** a transform is requested for a media item whose mime type is not an image type
- **THEN** the request is rejected without attempting a transform

### Requirement: Media serving

The system SHALL serve uploaded media files (and their cached transforms) over HTTP through a dedicated route that validates the requested path resolves inside the configured media storage directory, mirroring the traversal protection used for theme asset serving.

#### Scenario: Serve an original file

- **WHEN** a request is made for a previously uploaded media item's original file
- **THEN** the file is returned with the correct `Content-Type` header

#### Scenario: Path traversal is blocked

- **WHEN** a media serving request contains a path segment attempting to escape the storage directory (e.g. `../`)
- **THEN** the request is rejected without reading any file outside the storage directory
