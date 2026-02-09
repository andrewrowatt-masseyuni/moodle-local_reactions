[![Moodle Plugin CI](https://github.com/andrewrowatt-masseyuni/moodle-local_reactions/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/andrewrowatt-masseyuni/moodle-local_reactions/actions/workflows/moodle-ci.yml)
# Reactions

Modern emoji reactions for Moodle forum posts.

This local plugin adds an emoji reaction bar to forum posts, allowing users to react with configurable emoji (e.g. thumbs up, heart, laugh). Reactions are anonymous — only aggregate counts are displayed. Users can select multiple emoji per post.

## Features

-   GitHub-style trigger button with popup emoji picker
-   Reaction count pills displayed inline alongside post actions
-   Multi-react: users can add multiple different emoji to the same post
-   Per-forum enable/disable via forum activity settings (off by default)
-   Site-wide admin setting to enable/disable globally
-   Admin-configurable emoji set
-   Anonymous display (counts only), with full user logging in the database
-   Works with dynamically loaded inline replies via MutationObserver
-   Includes a course-wide Reactions report
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
2.  Enable the plugin globally with the **Enable reactions** setting.
3.  Optionally customise the emoji set (comma-separated `shortcode:emoji` pairs, e.g. `thumbsup:👍,heart:❤️,laugh:😂`).
4.  To enable reactions on a specific forum, edit the forum settings and tick **Enable emoji reactions** under the Reactions heading.

## Capabilities

| Capability              | Description                         | Default roles     |
|-------------------------|-------------------------------------|-------------------|
| `local/reactions:react` | React to forum posts with emoji     | Student and above |
| `local/reactions:view`  | View emoji reactions on forum posts | Guest and above   |

## License

2026 Andrew Rowatt [A.J.Rowatt@massey.ac.nz](mailto:A.J.Rowatt@massey.ac.nz)

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see <https://www.gnu.org/licenses/>.
