[![Moodle Plugin CI](https://github.com/andrewrowatt-masseyuni/moodle-local_reactions/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/andrewrowatt-masseyuni/moodle-local_reactions/actions/workflows/moodle-ci.yml)
# Reactions

Modern emoji reactions for Moodle forum posts, database activity entries and blog entries.

This local plugin adds an emoji reaction bar to forum posts, database activity entries and Moodle blog entries, allowing users to react with configurable emoji (e.g. thumbs up, heart, laugh). Reactions are anonymous — only aggregate counts are displayed. Users can select multiple emoji per item.

## Features

-   GitHub-style trigger button with popup emoji picker
-   Reaction count pills displayed inline alongside post actions
-   Multi-react: users can add multiple different emoji to the same item
-   Per-activity enable/disable via the forum or database activity settings (off by default)
-   Separate site-wide admin settings for forums, database activities and blog entries
-   Admin-configurable emoji set
-   Anonymous display (counts only), with full user logging in the database
-   Works with dynamically loaded inline replies via MutationObserver
-   Includes a course-wide Reactions report (forums only)
-   Optional Moodle App support for forum posts (see below)
-   Tested on Moodle 4.5 Boost theme and Snap theme

## Installing via uploaded ZIP file

1.  Log in to your Moodle site as an admin and go to *Site administration \> Plugins \> Install plugins*.
2.  Upload the ZIP file with the plugin code. You should only be prompted to add extra details if your plugin type is not automatically detected.
3.  Check the plugin validation report and finish the installation.

## Installing manually

The plugin can be also installed by putting the contents of this directory to

```
{your/moodle/dirroot}/local/reactions
```

Afterwards, log in to your Moodle site as an admin and go to *Site administration \> Notifications* to complete the installation.

Alternatively, you can run

```
$ php admin/cli/upgrade.php
```

to complete the installation from the command line.

## Configuration

1.  Go to *Site administration \> Plugins \> Local plugins \> Reactions*.
2.  Enable the content types you want with the **Enable reactions for Forums**, **Enable reactions for Database activities** and **Enable reactions for Blog posts** settings.
3.  Optionally customise the emoji set (comma-separated `shortcode:emoji` pairs, e.g. `thumbsup:👍,heart:❤️,laugh:😂`).
4.  To enable reactions on a specific forum or database activity, edit its settings and tick **Enable emoji reactions** under the Reactions heading.

## Database activities

Reactions appear on both the list view and the single view of a database activity.

Database activity entries are rendered through templates you can edit, so the plugin needs to know where the reactions bar belongs:

-   **Default templates (nothing to do).** While the activity uses Moodle's default list and single templates, the bar is placed automatically at the bottom of each entry.
-   **Custom templates and presets.** If you have edited a template, or applied a preset such as *Image gallery* or *Journal*, add this anchor to the template wherever you want the bar to appear:

    ```html
    <div data-region="local-reactions-anchor" data-recordid="##id##"></div>
    ```

    The `##id##` tag is replaced by Moodle with the entry's ID. Add the anchor to the list template, the single template, or both.

If a template is customised without an anchor and no longer contains the default entry wrapper, reactions are simply not shown for that view.

## Moodle App

Reactions are shown on forum posts in the Moodle App as well as on the web. Nothing extra needs
installing: make sure *Site administration \> Mobile app \> Mobile settings \> Enable web services
for mobile devices* is on, which it is by default.

The app caches plugin code at login, so after upgrading this plugin users need to **log out and
back in** before reactions appear.

### Scope and limitations

-   Forum posts only. Database activity entries and blog entries are web-only.
-   The discussion list in the app shows no reaction summary; bars appear on posts inside a
    discussion.
-   Reacting requires a connection. Reactions are not available offline and are not prefetched.
-   Administrators can switch the integration off under *Site administration \> Mobile app \>
    Mobile features* by disabling `sitePlugin_local_reactions_reactions`.
-   The app gives plugins no supported way to add anything to a forum post, so this works by
    watching the app's DOM and inserting bars into rendered posts. That markup is not something
    Moodle guarantees between app releases. The code is written defensively — if a future app
    version changes it, reactions stop appearing rather than anything breaking — but it does mean
    the integration needs re-checking when the app has a major update.

## Capabilities

| Capability              | Description                                                  | Default roles     |
|-------------------------|--------------------------------------------------------------|-------------------|
| `local/reactions:react` | React to forum posts and database entries with emoji         | Student and above |
| `local/reactions:view`  | View emoji reactions on forum posts and database entries     | Guest and above   |
| `local/reactions:viewreport` | View the course-wide reactions report                   | Teacher and above |

Blog entry reactions are gated on core's `moodle/blog:view` capability instead. Database activity reactions additionally require `mod/data:viewentry`.

## Reactions report

The course-wide Reactions report covers forums only. Database activity and blog reactions are stored and exported the same way, but do not yet appear in the report.

## License

2026 Andrew Rowatt [A.J.Rowatt@massey.ac.nz](mailto:A.J.Rowatt@massey.ac.nz)

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see <https://www.gnu.org/licenses/>.
