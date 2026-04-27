<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language pack for Reactions.
 *
 * @package    local_reactions
 * @category   string
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activeposters'] = 'Active posters';
$string['activereactors'] = 'Active reactors';
$string['addreaction'] = 'React with {$a}';
$string['allowmultiplereactions'] = 'Enable multiple reactions per-user per-post (Recommended)';
$string['allowmultiplereactions_help'] = 'When enabled, users can react to each post with more than one emoji. When disabled, each user may only react with a single emoji per post — clicking a new emoji automatically replaces their previous one.<br><br><strong>Note:</strong> This setting cannot be switched from multiple to single once reactions exist in the forum. You can always switch from single to multiple.';
$string['compactview_discuss'] = 'Use compact view for individual posts';
$string['compactview_discuss_help'] = 'When enabled, reactions on individual forum posts are displayed as a single pill showing all reacted emojis followed by the total count, similar to Facebook or WhatsApp.';
$string['compactview_list'] = 'Use compact view for discussion overview';
$string['compactview_list_help'] = 'When enabled, reactions on the discussion list page are displayed as a single pill showing all reacted emojis followed by the total count, similar to Facebook or WhatsApp.';
$string['dateheader'] = 'Date';
$string['enablereactions'] = 'Enable emoji reactions';
$string['enablereactions_help'] = 'When enabled, users can react to forum posts with emoji. Reactions are anonymous — only counts are displayed.';
$string['engagementhint'] = 'Shows if students are reading but not contributing.';
$string['forumheader'] = 'Forum';
$string['mostreactedposts'] = 'Most-reacted posts this week';
$string['needsattentionhint'] = 'Might need teacher response to kickstart engagement.';
$string['noposts'] = 'No posts found in forums with reactions enabled.';
$string['noreactedposts'] = 'No posts have received reactions this week.';
$string['noreactions'] = 'No reactions';
$string['onlypeerreactionsgrading'] = 'Only show peer reactions when grading';
$string['onlypeerreactionsgrading_help'] = 'When enabled, any self-reactions or reactions by non-students will be excluded and not displayed.';
$string['participationhint'] = 'Identifies different participation styles.';
$string['pluginname'] = 'Reactions';
$string['postheader'] = 'Post';
$string['postswithallreactions'] = 'All posts have received at least one reaction!';
$string['postswithzeroreactions'] = 'Posts with zero reactions';
$string['privacy:metadata:local_reactions'] = 'Stores emoji reactions made by users on forum posts and blog entries.';
$string['privacy:metadata:local_reactions:component'] = 'The component (e.g., mod_forum, core_blog) that the reaction is associated with.';
$string['privacy:metadata:local_reactions:emoji'] = 'The emoji reaction chosen.';
$string['privacy:metadata:local_reactions:itemid'] = 'The ID of the item (e.g., forum post, blog entry) being reacted to.';
$string['privacy:metadata:local_reactions:itemtype'] = 'The type of item (e.g., post, entry) being reacted to.';
$string['privacy:metadata:local_reactions:timecreated'] = 'The time the reaction was made.';
$string['privacy:metadata:local_reactions:userid'] = 'The ID of the user who reacted.';
$string['reactions:react'] = 'React to forum posts with emoji';
$string['reactions:view'] = 'View emoji reactions on forum posts';
$string['reactions:viewreport'] = 'View reactions report';
$string['reactionsheader'] = 'Reactions';
$string['reactionsnotenabled'] = 'Reactions are not enabled for this forum.';
$string['reactionsreport'] = 'Reactions report';
$string['reactionssettings'] = 'Reactions';
$string['reactionsvspostratio'] = 'Reactions to posts ratio';
$string['reacttothispost'] = 'React to this post';
$string['removereaction'] = 'Remove your {$a} reaction';
$string['report:activeparticipation'] = 'Active participation';
$string['report:engagement'] = 'Engagement overview';
$string['report:needsattention'] = 'Posts needing attention';
$string['report:topperformers'] = 'Top performers this week';
$string['settings:allowmultiplereactionsblog'] = 'Enable multiple reactions per-user per blog post';
$string['settings:allowmultiplereactionsblog_desc'] = 'If enabled, and there are multiple reactions by a single user on an single blog post then this setting cannot be disabled.';
$string['settings:allowmultiplereactionsblog_locked'] = 'This setting cannot be disabled because there are multiple reactions by a single user on a single blog post.';
$string['settings:emojis'] = 'Emoji set';
$string['settings:emojis_desc'] = 'Comma-separated list of shortcode:emoji pairs. For example: thumbsup:👍,heart:❤️,laugh:😂';
$string['settings:enabled'] = 'Enable reactions for Forums';
$string['settings:enabled_desc'] = 'When enabled, emoji reaction buttons will appear on forum posts (each forum still has its own reactions toggle on the forum settings form).';
$string['settings:enabledblog'] = 'Enable reactions for Blog posts';
$string['settings:enabledblog_desc'] = 'When enabled, emoji reaction buttons will appear on Moodle blog entries site-wide.';
$string['settings:pollinterval'] = 'Poll interval (seconds)';
$string['settings:pollinterval_desc'] = 'How often to check for new reactions from other users. Set to 0 to disable polling.';
$string['topperformershint'] = 'Quick pulse check on what\'s engaging students.';
$string['totalposts'] = 'Total posts';
$string['totalreactions'] = 'Total reactions';
