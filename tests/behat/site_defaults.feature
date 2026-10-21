@local @local_reactions @javascript
Feature: Site-wide defaults for the reactions settings of new activities
  As an admin I want to choose the starting reactions settings for new forums and database activities
  so that teachers get the site's preferred setup without having to remember it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    # Create the existing activities while reactions are off site-wide, so they have no stored
    # reactions settings, as for activities created before the plugin was installed.
    And the following config values are set as admin:
      | enabled     | 0 | local_reactions |
      | enableddata | 0 | local_reactions |
    And the following "activities" exist:
      | activity | name         | course | idnumber |
      | forum    | Old forum    | C1     | forum1   |
      | data     | Old database | C1     | data1    |
    And the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And I change the window size to "large"

  Scenario: With no defaults set, every reactions option on a new forum starts unticked
    Given I log in as "teacher1"
    When I add a "forum" activity to course "Course 1" section "1"
    And I expand all fieldsets
    And I set the field "Enable emoji reactions" to "1"
    Then the field "Use compact view for discussion overview" matches value "0"
    And the field "Use compact view for individual posts" matches value "0"
    And the field "Enable multiple reactions per-user per-post (Recommended)" matches value "0"
    And the field "Only show peer reactions when grading" matches value "0"
    And the field "Show who reacted (first names only)" matches value "0"

  Scenario: A new forum starts from the site-wide forum defaults
    Given the following config values are set as admin:
      | forumdefault_enabled                  | 1 | local_reactions |
      | forumdefault_compactview_list         | 1 | local_reactions |
      | forumdefault_allowmultiplereactions   | 1 | local_reactions |
      | forumdefault_onlypeerreactionsgrading | 1 | local_reactions |
    And I log in as "teacher1"
    When I add a "forum" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then the field "Enable emoji reactions" matches value "1"
    And the field "Use compact view for discussion overview" matches value "1"
    And the field "Use compact view for individual posts" matches value "0"
    And the field "Enable multiple reactions per-user per-post (Recommended)" matches value "1"
    And the field "Only show peer reactions when grading" matches value "1"
    And the field "Show who reacted (first names only)" matches value "0"

  Scenario: A new database activity uses its own defaults, not the forum ones
    Given the following config values are set as admin:
      | forumdefault_enabled            | 1 | local_reactions |
      | forumdefault_compactview_list   | 1 | local_reactions |
      | datadefault_compactview_discuss | 1 | local_reactions |
      | datadefault_shownames           | 1 | local_reactions |
    And I log in as "teacher1"
    When I add a "data" activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then the field "Enable emoji reactions" matches value "0"
    And I set the field "Enable emoji reactions" to "1"
    And the field "Use compact view for the list view" matches value "0"
    And the field "Use compact view for the single view" matches value "1"
    And the field "Enable multiple reactions per-user per-entry (Recommended)" matches value "0"
    And the field "Show who reacted (first names only)" matches value "1"

  Scenario: A new forum saved with reactions on by default shows reactions to students
    Given the following config values are set as admin:
      | forumdefault_enabled | 1 | local_reactions |
    And I log in as "teacher1"
    And I add a "forum" activity to course "Course 1" section "1" and I fill the form with:
      | Forum name | Defaults forum |
    And the following "mod_forum > discussions" exist:
      | user     | forum          | name        | message      |
      | teacher1 | Defaults forum | First topic | Hello there! |
    And I log out
    When I log in as "student1"
    And I am on the "Defaults forum" "forum activity" page
    And I follow "First topic"
    And I wait for reactions to load
    Then "[data-region='reactions-bar']" "css_element" should exist

  Scenario: Existing activities are not switched on by the site-wide defaults
    Given the following config values are set as admin:
      | forumdefault_enabled | 1 | local_reactions |
      | datadefault_enabled  | 1 | local_reactions |
    And I log in as "teacher1"
    When I am on the "Old forum" "forum activity editing" page
    And I expand all fieldsets
    Then the field "Enable emoji reactions" matches value "0"
    And I am on the "Old database" "data activity editing" page
    And I expand all fieldsets
    And the field "Enable emoji reactions" matches value "0"
