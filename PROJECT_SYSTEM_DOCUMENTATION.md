# TNTS Project System Documentation

Review date: 2026-03-15
Project root: `C:\xampp\htdocs\tnts`
Scope: entire project folder except `three.js-master/`, which was intentionally excluded from code examination as requested.

This document reviews the application code, data files, assets, models, and notable runtime artifacts that exist in the `tnts` folder. It focuses on what each file is responsible for, what features it enables, and what parts of the system are complete versus placeholder-only.

Git internals under `.git/` are not enumerated file-by-file because they are repository metadata rather than application logic. They are still noted in the tooling section.

## 1. System Overview

The TNTS project is a PHP and MySQL web application for a school/campus navigation kiosk. Its main feature is a 3D map viewer built with Three.js, backed by a large admin toolset for importing, editing, annotating, exporting, and publishing GLB campus models.

At a high level, the system has four major parts:

1. Public kiosk pages for map browsing, facility browsing, events, announcements, and feedback.
2. A live 3D public map runtime that can search buildings and rooms, display details, and show directions from a kiosk start point.
3. An admin side for authentication, dashboarding, map editing, building editing, room editing, and model import/publish workflows.
4. File-based model state stored as GLB and JSON, combined with MySQL metadata for buildings, rooms, and admin accounts.

## 2. What the Current System Can Do

The implemented system can currently do all of the following:

- Authenticate administrators using usernames and hashed passwords.
- Show an admin dashboard with counts for buildings, rooms, facilities, events, and recent announcements.
- Load a public 3D campus model and refresh it when a newer published map is detected.
- Search buildings and rooms from the public interface.
- Show building descriptions and associated room lists in the public info card.
- Compute or replay routes from a kiosk start point to a selected building.
- Import building and room entities from a GLB model into the database.
- Edit building metadata while also saving an updated GLB model.
- Edit room metadata while also saving an updated GLB model.
- Author road networks, route anchors, kiosk points, and overlay assets in a full-screen 3D map editor.
- Export edited scenes into new GLB files and publish a selected model as the live public map.
- Maintain backups, per-model road graphs, per-model saved routes, and release history.

The following areas are present but not yet fully implemented:

- Public announcements page.
- Public feedback page.
- Admin facilities CRUD.
- Admin events CRUD.
- Admin announcements CRUD.
- Admin user CRUD page beyond login itself.
- Legacy standalone search page.

## 3. Important Current State

Based on the files in `admin/overlays/`, the project is currently configured like this:

- Default model: `tnts_map_export_20260314_154422.glb`
- Live published model: `tnts_map_export_20260314_154422.glb`
- Live routes file: `routes_tnts_map_export_20260314_154422.glb.json`
- Live version string: `20260315155738_75955b86`
- Live publish time from `map_live.json`: 2026-03-15 22:57:38 local time

This means the system is currently serving an exported map, not the original `tnts_navigation.glb`, as its active public model.

## 4. Architecture Summary

### Public layer

Public pages live mostly under `pages/` and use `ui/layout.php` plus `ui/nav.php` for a consistent shell. The main interactive experience is `pages/map.php`, which loads `js/map3d.js`.

### API layer

The public map calls small PHP endpoints in `api/` to discover the currently published model, fetch search data, and fetch building plus room details.

### Admin layer

The admin area uses PHP sessions and the shared files under `admin/inc/`. The most important admin modules are:

- `admin/mapEditor.php` for 3D overlay editing, route authoring, model export, and live publication.
- `admin/mapImport.php` for scanning a GLB scene and importing buildings and rooms into MySQL.
- `admin/building.php` for editing building metadata and GLB changes.
- `admin/room.php` for editing room metadata and GLB changes.

### Data layer

Persistent state is split across MySQL and filesystem storage:

- MySQL stores admin accounts and map-related metadata such as buildings and rooms.
- `models/` stores the base and exported GLB model files.
- `models/backups/` stores safety backups.
- `admin/overlays/` stores JSON data for live map state, releases, routes, road networks, and overlay objects.
- `admin/assets_map/` stores placeable 3D editor assets.

### Three.js dependency note

The active application files do not import the vendored copy under `libs/three/`. Instead, the public map and admin map tools all point to `three.js-master/` through import maps. That folder was excluded from examination by request, but it is still operationally required by the current PHP pages.

## 5. Known Caveats and Gaps

- `searchPage.php` includes `nav.php` and `kiosk.js`, but both files are missing from the root project folder.
- `pages/events.php` references image files under `assets/events/`, but that directory does not exist in the current repo snapshot.
- `pages/facilities.php` references image files under `assets/facilities/`, but that directory does not exist in the current repo snapshot.
- Several admin pages are UI-only mock forms with no backend persistence.
- `admin_dashboard_ui.php` is a mockup and not the real admin dashboard used by the application.
- `chrome_dom.html` and `edge_dom.html` are empty placeholder files.
- `.chrome-headless/` and `tmp_sessions/` contain runtime artifacts, not deployable feature code.

## 6. File-by-File Documentation

### Top-level files

`searchPage.php`
Legacy standalone kiosk search page. It outputs a simple search screen with title, subtitle, input field, and search button, then attempts to include a root-level navigation file and a `kiosk.js` script. Because `nav.php` and `kiosk.js` are missing, this page is incomplete and appears to be an older experiment rather than the active kiosk search implementation.

`searchPage.css`
Styles the legacy search page. It exists only to support `searchPage.php` and is not part of the newer shared `ui/` layout system.

`admin_dashboard_ui.php`
Standalone admin dashboard mockup made with CDN Bootstrap and Font Awesome. It simulates switching between sections by replacing inner HTML in JavaScript, but it does not connect to the actual admin PHP backend, database, or session system. This is useful as a design prototype, not as a production module.

`chrome_dom.html`
Empty HTML file. It does not provide application behavior.

`edge_dom.html`
Empty HTML file. It does not provide application behavior.

`city_map.glb`
Standalone 3D model file in the project root. It is not part of the current published-map pipeline, which uses `models/` plus `admin/overlays/` manifests.

`tnts_map.glb`
Older or alternate GLB model stored at the root. It appears to be a legacy asset outside the main editor and publish workflow.

`tnts_map1.glb`
Another root-level alternate GLB file. It is not referenced by the current live map flow.

`tnts_map3.glb`
Another root-level alternate GLB file. It is also outside the current publish pipeline.

`PROJECT_SYSTEM_DOCUMENTATION.md`
This generated documentation file. It provides the project-wide review requested for manuscript use.

### Shared public UI

`ui/layout.php`
Shared public page shell. It builds the top search bar, includes the public CSS files, inserts the bottom navigation via `ui/nav.php`, and renders page-specific content blocks through `$content`, `$extraHead`, and `$extraScripts`. This is the main template used by the newer public pages.

`ui/nav.php`
Bottom navigation bar for the public kiosk. It provides links to the map, facilities, events, announcement, and feedback pages, and highlights the active section.

### Public CSS

`css/app.css`
Core styling for the public layout shell, including the top bar, search field, page spacing, and overall app framing.

`css/nav.css`
Styles the shared bottom navigation UI used by `ui/nav.php`.

`css/map.css`
Styles public map-specific elements such as the 3D stage, building info card, room list, and supporting overlays.

### Public pages

`pages/map.php`
Main public map page. It uses the shared layout, creates the 3D map container and info card, defines a module import map pointing to `three.js-master`, and loads `js/map3d.js`. This is the primary public-facing navigation experience.

`pages/facilities.php`
Facility listing page using hard-coded cards rather than database content. It has category filters for `all`, `academic`, and `services`, and its "VIEW DETAILS" buttons write a building key into `sessionStorage` under `tnts:jumpToBuilding` before redirecting to the map page. The image paths point to `assets/facilities/`, which is currently missing, so the code falls back to a TNTS placeholder mark when an image fails to load.

`pages/events.php`
Static event listing page backed by a hard-coded PHP array of event data. It uses a native browser date picker to filter cards by month and currently shows a placeholder alert when "VIEW DETAILS" is pressed. The page expects images in `assets/events/`, which does not exist in the current repo, so failed images collapse into neutral placeholders.

`pages/announcement.php`
Public announcement page placeholder. It only renders a heading and a note that the database-backed implementation is for later.

`pages/feedback.php`
Public feedback page placeholder. It only renders a heading and a note that the future database-backed implementation is pending.

### Public JavaScript

`js/map3d.js`
This is the public 3D map runtime and one of the most important files in the project. It imports Three.js modules, boots the live model by calling `api/map_live.php`, and falls back to `models/tnts_navigation.glb` if no published map is available.

Major features inside this file include:

- Loading the currently published model and checking periodically for updates.
- Projecting building labels and the kiosk marker into screen space.
- Loading a searchable building and room catalog from `api/map_search.php`.
- Supporting search result selection for both buildings and rooms.
- Loading building metadata and room lists into the info card from `api/map_building_details.php`.
- Highlighting buildings on hover and selection.
- Drawing and animating a route ribbon for directions.
- Handling room-to-building resolution when the user searches for a room.
- Supporting touch interactions such as pinch zoom and tap filtering.

In practical terms, this file is what turns the map from a static 3D model into an interactive kiosk navigation system.

### API endpoints

`api/map_live.php`
Public API endpoint that resolves the currently live map model and route data. It uses helpers from `admin/inc/map_sync.php`, builds a version token from file signatures, and returns JSON containing the live model filename, route filename, published state, and version information. The public map viewer depends on this endpoint to know what model to load.

`api/map_search.php`
Public API endpoint that returns the searchable catalog of buildings and rooms. It applies model-aware filtering when the schema supports `source_model_file`, removes duplicates, and prepares data for the public map's search interface.

`api/map_building_details.php`
Public API endpoint that returns one building plus its related rooms. It supports loading by building ID or name, filters by model when relevant, and is used to populate the public info card after selection.

### Admin shared infrastructure

`admin/inc/db.php`
Database connection bootstrap. It connects to MySQL using the `tnts_kiosk` database with `utf8mb4` and exposes a `mysqli` connection object for the admin modules and APIs.

`admin/inc/auth.php`
Session and access-control helper. It starts a session if needed, exposes helper functions for the current admin identity, and provides `require_admin()` to protect admin-only pages.

`admin/inc/layout.php`
Shared admin shell. It prints the admin sidebar, page title area, current admin identity, and logout link, then wraps page content in the admin UI frame. Most real admin pages use this layout.

`admin/inc/map_sync.php`
Shared map state helper for resolving which model should be considered public. It contains filesystem path helpers, resolves the active model from `map_live.json` and `default_model.json`, and can rename route keys when a building name changes. This file is important because it ties together public map serving and admin publishing logic.

### Admin authentication and session pages

`admin/login.php`
Real login page and authentication handler. It checks the submitted username against the `admin_users` table, verifies the password using `password_verify`, records the admin session, updates `last_login`, and redirects to the dashboard when successful.

`admin/logout.php`
Logout endpoint. It clears the session and sends the admin back to the login page.

`admin/seed_admin.php`
Commented-out setup script intended to create a default admin account. It is not active code in its current form, but it documents how an initial user could be seeded.

### Admin dashboard and placeholder management pages

`admin/dashboard.php`
Real admin dashboard. It counts buildings, rooms, facilities, and events, and it lists recent non-expired announcements. It is also model-aware for building and room counts, preferring data from the currently active public model when the schema supports versioned map metadata.

`admin/adminUser.php`
UI-only admin-user management page. It renders a form for full name, username, password, and role, plus a table with placeholder rows and inert Edit/Delete buttons. The page is protected by login, but it does not implement real create, update, or delete behavior.

`admin/announcement.php`
UI-only announcement management page. It displays form fields for title, content, and expiry date, plus a placeholder table and buttons, but no persistence or CRUD logic is present.

`admin/events.php`
UI-only event management page. It renders title, description, start date, and end date fields, along with a placeholder table and inert action buttons.

`admin/facilities.php`
UI-only facilities management page. It contains a form for name, description, floor, and location, plus a placeholder "Top View Location Picker" area and a non-functional facilities table. It indicates a plan to bind facilities to map coordinates later, but that feature is not implemented here.

### Admin building editor

`admin/building.php`
Full building editor that combines database editing with GLB model manipulation. This file is significantly more than a CRUD form; it is a browser-based 3D building selection and save workflow.

Its backend PHP logic includes:

- CSRF protection.
- Model-file validation.
- Atomic file writing and backup creation.
- On-demand schema preparation for model-aware building metadata.
- Building list and building metadata APIs.
- Saving an uploaded or exported GLB back to the selected model.
- Upserting building metadata into MySQL.
- Synchronizing room records when a building name changes.
- Renaming route entries so saved navigation data follows the new building name.

Its frontend JavaScript includes:

- Three.js scene loading and navigation.
- Selection of top-level building objects from the model.
- Hover and active selection highlighting with helpers.
- Loading metadata for the selected building into the form.
- Exporting the edited scene back to GLB for save.
- Listing building metadata for the selected model.

This file lets an admin use the 3D scene itself as the context for building-level editing.

### Admin room editor

`admin/room.php`
Full room editor with both metadata management and GLB save support. It is parallel to `admin/building.php` but more complex because room names require normalization and building linkage.

Its backend logic includes:

- CSRF protection and GLB validation.
- Backup creation before writing model changes.
- Schema creation or upgrade for room metadata.
- Room name variant normalization such as `ROOM101`, `ROOM_101`, and `ROOM 101`.
- Resolution of the correct building associated with a room.
- Cloning or ensuring building metadata exists for the current model when needed.
- Upserting room metadata into MySQL with model/version tracking.

Its frontend logic includes:

- Three.js-based room picking from the loaded model.
- Detection of room objects by name patterns.
- Room mesh indexing so clicking a sub-mesh still selects the correct room object.
- Form loading and save operations for room metadata.
- A room metadata table per model.

This file is effectively a model-aware room administration tool rather than a simple text-based room list.

### Admin map import pipeline

`admin/mapImport.php`
Dedicated import and normalization tool for 3D map data. It scans a selected GLB file, detects buildings and rooms from object names, fixes naming inconsistencies, optionally rewrites the corrected GLB, and imports the resulting entities into MySQL.

Backend responsibilities include:

- CSRF and origin validation.
- Listing GLB models and backup files.
- Validating uploaded GLB data before overwrite.
- Refusing suspicious overwrites if the corrected file appears to lose too many nodes.
- Creating and restoring backups.
- Ensuring import-related schema exists.
- Importing or updating buildings and rooms for a specific model version.
- Marking stale rows as not present in the latest import.

Frontend responsibilities include:

- Loading a live model or backup model into Three.js.
- Traversing the scene to discover building and room structures.
- Normalizing duplicate room names from Blender or GLTF-generated suffixes.
- Showing correction previews and audit data before import.
- Saving corrected names back into the model.
- Importing the scan results into the database.
- Restoring the latest or selected backup.

This file is the bridge between raw 3D authoring output and structured database metadata.

### Admin master map editor

`admin/mapEditor.php`
This is the largest and most advanced file in the project. It is a full-screen 3D authoring environment used to add overlay assets, create roads and route graphs, manage kiosk start points, export new map versions, and publish a model live.

Major backend capabilities:

- Listing placeable asset files from `admin/assets_map/`.
- Loading and saving per-model route JSON.
- Loading and saving per-model road-network JSON.
- Listing available base models.
- Getting and setting the default model.
- Publishing a selected model by updating `map_live.json`, `default_model.json`, and `map_releases.json`.
- Loading and saving the legacy overlay object store.
- Exporting a new GLB file and cloning database snapshot state for that model.
- Creating and updating model/version snapshot metadata.

Major frontend capabilities:

- Loading the base map model and overlay state into a Three.js editing scene.
- Showing a building list and searchable asset list.
- Placing, moving, rotating, scaling, locking, and deleting overlay objects.
- Undo and redo support.
- Switching tools such as move, rotate, scale, road, and delete.
- Creating roads interactively from points in the scene.
- Snapping road angles and endpoints.
- Attaching road endpoints to building entrances.
- Defining a kiosk start point.
- Auto-generating road intersections and graph nodes.
- Calculating navigation routes using a road graph and Dijkstra-style shortest path logic.
- Saving routes and road graphs separately per model.
- Exporting a composed map scene to a new GLB.
- Publishing the current model as the live public map.
- Tracking dirty state and warning about unsaved changes before leaving.

This file is the operational center of the 3D map authoring system. It turns the project from a viewer into an editable, publishable campus navigation platform.

### Admin styling and minor support files

`admin/assets/admin.css`
Shared stylesheet for the real admin area. It defines the admin dashboard layout, sidebar, cards, tables, forms, and action button styles used by the PHP admin pages.

`admin/desktop.ini`
Windows metadata file. It localizes the display name for `mapEditor.php` in Windows Explorer and has no effect on web application behavior.

### Admin editor assets

`admin/assets_map/ADMINxSOCIAL_SOCIAL_HALL BLDG.glb`
Placeable GLB overlay asset for the map editor. It can be inserted into the editing scene as an auxiliary object.

`admin/assets_map/room.glb`
Another placeable GLB asset used by the map editor.

`admin/assets_map/room.blend`
Blender source file corresponding to a placeable room asset. It is authoring-source content rather than runtime code.

### Admin overlay and publication state files

`admin/overlays/default_model.json`
Stores the model file currently considered the default base map. At the time of review it points to `tnts_map_export_20260314_154422.glb`.

`admin/overlays/map_live.json`
Stores the currently published public map state. At the time of review it points to `tnts_map_export_20260314_154422.glb` with routes file `routes_tnts_map_export_20260314_154422.glb.json` and version `20260315155738_75955b86`.

`admin/overlays/map_releases.json`
Append-only style release history for published map versions. It records which model and routes file were live at each publication event.

`admin/overlays/map_overlay.json`
Legacy overlay object store. It contains saved editor overlay data and appears to preserve scene objects for the original map workflow.

`admin/overlays/roadnet_tnts_navigation.glb.json`
Road-network data for the original base model. This is where authored roads and graph points for `tnts_navigation.glb` are stored.

`admin/overlays/roadnet_tnts_map_export_20260314_154422.glb.json`
Road-network data for the currently exported model. It allows the published export to have its own editable route graph independent of the original model.

`admin/overlays/routes_tnts_navigation.glb.json`
Saved route output for the original base model. It stores building-target route paths in JSON form.

`admin/overlays/routes_tnts_map_export_20260314_154422.glb.json`
Saved route output for the current exported live model. This is the route dataset the public map is expected to use for the active published model.

### Core 3D model files

`models/tnts_navigation.glb`
Primary source model for the campus. It acts as the original baseline model, the fallback public model, and the starting point for import and editing workflows.

`models/tnts_map_export_20260314_154422.glb`
Exported model generated by the editor workflow. It is currently both the default model and the live public model.

### Backup model files

`models/backups/tnts_navigation_backup_20260314_082954.glb`
Automatic backup snapshot of the original navigation model. It exists for restore and rollback safety.

`models/backups/tnts_navigation_backup_20260315_153114.glb`
Another automatic backup snapshot of the original navigation model created during later editing or import operations.

### Public assets

`assets/logo.jpg`
Branding image used by the project.

`assets/logo.png`
Branding image used by the project.

`assets/tntsMap_1.png`
Static map image asset. It appears to be a 2D map or supporting visual resource.

`assets/tntsMap_2.png`
Static map image asset. It appears to be a second 2D map or supporting visual resource.

### Vendored Three.js library files

`libs/three/three.module.js`
Vendored core Three.js ES module. It is library code, not project-authored feature logic.

`libs/three/addons/loaders/GLTFLoader.js`
Vendored Three.js GLTF loader module used for GLB and GLTF model loading in Three.js projects.

`libs/three/addons/controls/OrbitControls.js`
Vendored Three.js orbit camera control module.

Important note: these vendored files are present in the repository, but the active pages inspected in this project do not import them. Instead, the runtime points to `three.js-master/`.

### Tooling and runtime artifacts

`.vscode/extensions.json`
Workspace recommendation file for Visual Studio Code. It suggests the `openai.chatgpt` extension and does not affect production behavior.

`.chrome-headless/`
Headless Chromium runtime folder containing crash reporting files such as Crashpad metadata, reports, settings, and variation state. These are environment artifacts, not application features.

`tmp_sessions/sess_6s9mlo4cr3fce5t025je1a1jct`
Runtime PHP session file. It stores temporary session data and is not source code.

`tmp_sessions/sess_e6evfj4rnb7mnu35aq2j82rgid`
Runtime PHP session file. It stores temporary session data and is not source code.

`tmp_sessions/sess_ujj2stnfing1knjutf1inbp5gr`
Runtime PHP session file. It stores temporary session data and is not source code.

`.git/`
Git repository metadata and version history. This includes commit references, object storage, logs, hooks, and configuration. It is essential for source control but not part of the deployed kiosk application itself.

## 7. Functional Status by Area

### Fully or substantially implemented

- Public 3D map viewing and routing.
- Public search across buildings and rooms.
- Building detail display with room lists.
- Admin authentication.
- Admin dashboard.
- GLB model import and normalization.
- Building editor.
- Room editor.
- Full map editor with publish/export workflow.
- Route and road-network persistence.

### Present but clearly placeholder-only

- Public announcement page.
- Public feedback page.
- Admin facilities management page.
- Admin events management page.
- Admin announcement management page.
- Admin user management page.
- Legacy standalone search page.
- Mockup-only `admin_dashboard_ui.php`.

## 8. Suggested Manuscript Description

If you need a short system description for your manuscript, the codebase supports the following summary:

"The TNTS system is a web-based campus navigation kiosk built with PHP, MySQL, and Three.js. It provides a public 3D campus map with searchable buildings and rooms, building detail panels, and path guidance from a kiosk start point. On the administrative side, the system includes authenticated tools for importing 3D GLB models, editing building and room metadata in context, authoring navigation roads and routes, exporting revised map versions, and publishing a selected model as the live campus map."

## 9. Review Scope Summary

- Reviewed non-`.git`, non-`three.js-master` project files currently present: 74
- Reviewed text/code/config/data files among those: 49
- Excluded from code examination by request: `three.js-master/`
- Summarized as repository metadata rather than application logic: `.git/`

If this document will be turned into a thesis or manuscript section, the strongest chapters to expand are:

- Public system features and kiosk workflow.
- Admin workflow for map maintenance.
- 3D model import and normalization.
- Route authoring and publication pipeline.
- Current limitations and unfinished modules.
