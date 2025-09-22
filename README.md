# Access Display

## Overview

The Access Display module provides a real-time, de-duplicated presence feed designed for kiosk displays. It listens for access control events from a compatible logging module and presents a visually clean, automatically updating grid of users who have recently gained access.

This module is ideal for scenarios like building entrances, event check-ins, or any situation where a public-facing screen needs to show who has recently entered a space.

## Features

*   **Real-Time Updates**: The kiosk page polls for new access events every 7 seconds, ensuring the display is always up-to-date without requiring a page refresh.
*   **Event De-duplication**: Prevents a single user entry from creating multiple display cards. If a user triggers multiple access events within a 5-minute window, the system intelligently groups them into a single event, updating the timestamp and incrementing a counter.
*   **Customizable Kiosk Display**: The display page is a self-contained HTML page. You can apply custom CSS directly from the module's settings page to match your organization's branding.
*   **Simple & Secure Display URL**: Access to the kiosk page is protected by a configurable "code word," preventing unauthorized viewing or scraping.
*   **Flexible Filtering**: The display can be filtered by a specific user permission or a "source" (e.g., a specific door name), allowing you to create different displays for different contexts from a single data source.
*   **Drupal Integration**: Seamlessly integrates with Drupal's User system, the `profile` module for user photos, and the `image` module for applying image styles.

## Installation and Configuration

### Dependencies

1.  **A Logging Module**: This module depends on another module that creates log entities with the machine name `access_control_log` and the bundle `access_control_request`. This source module must provide the fields `field_access_request_user`, `field_access_request_result`, and `field_access_request_permission`.
2.  **Profile Module (Optional but Recommended)**: To display user photos, the core `profile` module is required. The module looks for a profile type with the machine name `main` and a photo field with the machine name `field_member_photo`.
3.  **Image Module (Optional but Recommended)**: To apply image styles to the user photos, the core `image` module is needed.

### Steps

1.  Install the module as you would any other Drupal module.
2.  Run database updates to create the `access_display_presence` table. You can do this with Drush (`drush updb -y`) or by visiting `/update.php` in your browser.
3.  Navigate to the settings page at **Administration > Configuration > System > Access Display** (`/admin/config/system/access-display`).
4.  **Configure the settings:**
    *   **Image Style**: Select the image style you want to use for user photos on the display.
    *   **Code Word**: Set a secret code word to protect the display page URL.
    *   **Custom CSS**: (Optional) Add your own CSS to style the kiosk page. The default CSS provides a clean, dark-themed grid layout.
5.  Save the configuration.

## Usage

The access display page is available at a dynamic URL that you construct based on your needs. The basic structure is:

`/display/access-request/{code_word}/{permission}/{source}`

*   `{code_word}`: The secret code word you configured in the settings. If you left it blank, you can omit this part of the URL.
*   `{permission}`: (Optional) The machine name of a Drupal user permission. The display will only show users who have a role with this permission. Use `_all` to show all users regardless of permissions.
*   `{source}`: (Optional) The name of the source (e.g., a door name like `main-entrance`) to filter by.

### Examples

*   **Display all recent entries:**
    `/display/access-request/kiosk123/_all`

*   **Display entries for users with the `can_enter_building` permission:**
    `/display/access-request/kiosk123/can_enter_building`

*   **Display entries at the "Main Entrance" for users with the `can_enter_building` permission:**
    `/display/access-request/kiosk123/can_enter_building/Main%20Entrance`

## Technical Data Flow

For developers and advanced users, here is a summary of how data moves through the module:

1.  **Entity Insert**: The module uses `hook_entity_insert` to listen for new `access_control_log` entities.
2.  **Data Processing**: When a valid log entry is detected (i.e., access was granted), the `access_display.updater` service is called.
3.  **Upsert**: The `PresenceUpdater::upsert()` method processes the data. It inserts a new record into the `access_display_presence` table or updates an existing one, handling the de-duplication logic.
4.  **Display Rendering**: The `DisplayController` handles requests for the `/display/access-request/...` URL. It verifies the code word and renders a self-contained HTML page with the necessary JavaScript.
5.  **JSON Feed**: The JavaScript on the display page makes periodic requests to the `PresenceFeedController` at `/access-display/presence/...`. This controller queries the `access_display_presence` table, filters the results, and returns the data as a JSON object, ready to be rendered on the kiosk display.
