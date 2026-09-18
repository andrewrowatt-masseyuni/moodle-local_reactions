# Changelog

## v1.10.0
- Added a per-activity **Show who reacted (first names only)** setting for forums and database activities, off by default. With it on, each reaction pill carries a tooltip naming the people behind it, most recent reaction first — for example `You, Jenny (Teacher), Andrew, Bancy, and 2 others`.
- Thanks to evertonaltas for requesting this.
- Students see at most three other names. Users with the new `local/reactions:viewallreactornames` capability (teachers and managers by default) see up to the new site-wide **Maximum names shown to teachers** setting, which defaults to 10.
- Names are shown on forum posts, the forum discussion list, database activity entries and the whole-forum grading screen, where they honour **Only show peer reactions when grading**. Names are not shown in the Moodle App.
- Fixed a `get_role_users()` developer warning when collecting the students of a course for peer-reaction filtering, which also made that lookup fall back to one query per student role.

## v1.10.0
- Added a per-activity **Show who reacted (first names only)** setting for forums and database activities, off by default. With it on, each reaction pill carries a tooltip naming the people behind it, most recent reaction first — for example `You, Jenny (Teacher), Andrew, Bancy, and 2 others`.
- Students see at most three other names. Users with the new `local/reactions:viewallreactornames` capability (teachers and managers by default) see up to the new site-wide **Maximum names shown to teachers** setting, which defaults to 10.
- Names are shown on forum posts, the forum discussion list, database activity entries and the whole-forum grading screen, where they honour **Only show peer reactions when grading**. Names are not shown in the Moodle App.
- Fixed a `get_role_users()` developer warning when collecting the students of a course for peer-reaction filtering, which also made that lookup fall back to one query per student role.

## v1.9.1
- Added support for "A single simple discussion" forum type.
- Thanks to Raymond Sii for requesting this.

## v1.9.0
- Reactions now appear on forum posts in the Moodle App. Nothing extra to install; users need to log out and back in after the upgrade so the app picks up the plugin. See the Moodle App section of the README for the current limitations (forum posts only, no offline support).
- The `local_reactions_toggle_reaction`, `local_reactions_get_reactions` and `local_reactions_get_discussion_reactions` web services are now available to the Moodle App service.
- Added the `local_reactions_get_item_settings` web service, which returns the reactions configuration and the site's emoji set for a list of items.
- Thanks for the suggestion Robert Schrenk.

## v1.8.0
- Reactions are now available on Database activity entries, on both the list view and the single view. Enable them site-wide with the new **Enable reactions for Database activities** setting, then per activity on the database activity settings form.
- Database activities using custom templates or presets can place the bar themselves by adding `<div data-region="local-reactions-anchor" data-recordid="##id##"></div>` to the template.
- Backup, restore, privacy export/delete and entry-deletion cleanup all cover database activity reactions.
- Thanks for the suggestion Michelle Doyle.

## v1.7.1
- Added site-wide "Enable multiple reactions per-user per blog post" setting (off by default). The setting locks in the "on" position once a user has stacked more than one emoji on a single blog entry.

## v1.7.0
- Reactions are now available on Moodle core blog entries. Thanks for the suggestion Hananoshika Yomaru.

## v1.6.1
- Reactions are now visible on the forum grading screen. Self and teacher reactions are excluded by default.

## v1.6.0
- Internal testing version only. Never released.

## v1.5.0
- Internal testing version only. Never released.

## v1.4.0
- Added option for a single reaction per post per user. Thanks to Sokunthearith "T" Makara for the suggestion.

## v1.3.0
- Added backup and restore functionality
- Added reactions to standalone forum post reply page

## v1.1.1
- Minor changes from code review

## v1.0.0
- Initial release with emoji reactions for Moodle forum posts