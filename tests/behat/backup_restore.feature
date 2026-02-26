@local @local_reactions @javascript
Feature: Backup and restore forum with reactions
  As a teacher I want to duplicate or backup/restore a forum that has reactions
  and verify that settings and reaction data are preserved.

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
    And the following "activities" exist:
      | activity | name       | course | type    | idnumber |
      | forum    | Test Forum | C1     | general | forum1   |
    # Enable the reactions plugin globally.
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    # Enable reactions for the forum.
    And the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled |
      | Test Forum | C1     | 1       |
    # Student creates a discussion.
    And the following "mod_forum > discussions" exist:
      | user     | forum  | name         | message              |
      | student1 | forum1 | Student Post | Hello from a student |
    # Teacher reacts with thumbsup to the post.
    And the following "local_reactions > reactions" exist:
      | user     | post         | emoji    |
      | teacher1 | Student Post | thumbsup |
    # Teacher replies to the post.
    And the following "mod_forum > posts" exist:
      | user     | parentsubject | subject       | message              |
      | teacher1 | Student Post  | Teacher Reply | Great post, student! |
    # Student reacts with heart to the post.
    And the following "local_reactions > reactions" exist:
      | user     | post         | emoji |
      | student1 | Student Post | heart |

  Scenario: Duplicate a forum preserves the reactions enabled setting
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I duplicate "Test Forum" activity
    And I should see "Test Forum (copy)"
    # Open the duplicated forum's settings and verify reactions are enabled.
    And I am on the "Test Forum (copy)" "forum activity editing" page
    And I expand all fieldsets
    Then the field "Enable emoji reactions" matches value "1"

  Scenario: Backup and restore a course preserves the single reaction setting
    # Disable async backup for Behat.
    Given the following config values are set as admin:
      | enableasyncbackup | 0 |
    # Switch the test forum to single-reaction mode.
    And the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | allowmultiplereactions |
      | Test Forum | C1     | 1       | 0                      |
    And I log in as "admin"
    And I backup "Course 1" course using this options:
      | Initial      | Include enrolled users | 1                     |
      | Confirmation | Filename               | test_single_react.mbz |
    And I restore "test_single_react.mbz" backup into a new course using this options:
      | Schema | Course name       | Course 1 single restored |
      | Schema | Course short name | C1SR                     |
    Then I should see "Test Forum"
    When I follow "Test Forum"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "Enable multiple reactions per-user per-post (Recommended)" matches value "0"

  Scenario: Backup and restore a course preserves reactions with user data
    # Disable async backup for Behat.
    Given the following config values are set as admin:
      | enableasyncbackup | 0 |
    And I log in as "admin"
    And I backup "Course 1" course using this options:
      | Initial      | Include enrolled users | 1               |
      | Confirmation | Filename               | test_backup.mbz |
    And I restore "test_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Course 1 restored |
      | Schema | Course short name | C1R               |
    Then I should see "Test Forum"
    # Verify reactions are enabled on the restored forum via its edit form.
    When I follow "Test Forum"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the field "Enable emoji reactions" matches value "1"
    # Navigate to the discussion and verify reaction counts were restored.
    When I press "Save and return to course"
    And I follow "Test Forum"
    And I follow "Student Post"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 1
    And the "heart" reaction count should be 1
