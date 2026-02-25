@local @local_reactions @javascript
Feature: Single reaction per post setting
  As a teacher I want to configure a forum to allow only one reaction per user per post
  so that I can control how students engage with content.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name       | course | type    | idnumber |
      | forum    | Test Forum | C1     | general | forum1   |
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    And I change the window size to "large"

  Scenario: Teacher can change from multiple to single reactions when no reactions exist
    Given the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | allowmultiplereactions |
      | Test Forum | C1     | 1       | 1                      |
    When I log in as "teacher1"
    And I am on the "Test Forum" "forum activity editing" page
    And I expand all fieldsets
    Then the field "Enable multiple reactions per-user per-post (Recommended)" matches value "1"
    And I set the field "Enable multiple reactions per-user per-post (Recommended)" to "0"
    And I press "Save and return to course"
    Then I should see "Test Forum"

  Scenario: Multiple to single setting is locked when reactions exist
    Given the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | allowmultiplereactions |
      | Test Forum | C1     | 1       | 1                      |
    And the following "mod_forum > discussions" exist:
      | user     | forum      | name      | message   |
      | student1 | Test Forum | Test Post | A message |
    And the following "local_reactions > reactions" exist:
      | user     | post      | emoji    |
      | student2 | Test Post | thumbsup |
    When I log in as "teacher1"
    And I am on the "Test Forum" "forum activity editing" page
    And I expand all fieldsets
    Then the field "Enable multiple reactions per-user per-post (Recommended)" matches value "1"
    And the "local_reactions_allowmultiplereactions" "field" should be disabled

  Scenario: Teacher can change from single to multiple reactions even when reactions exist
    Given the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | allowmultiplereactions |
      | Test Forum | C1     | 1       | 0                      |
    And the following "mod_forum > discussions" exist:
      | user     | forum      | name      | message   |
      | student1 | Test Forum | Test Post | A message |
    And the following "local_reactions > reactions" exist:
      | user     | post      | emoji    |
      | student2 | Test Post | thumbsup |
    When I log in as "teacher1"
    And I am on the "Test Forum" "forum activity editing" page
    And I expand all fieldsets
    Then the field "Enable multiple reactions per-user per-post (Recommended)" matches value "0"
    And the "local_reactions_allowmultiplereactions" "field" should be enabled
    And I set the field "Enable multiple reactions per-user per-post (Recommended)" to "1"
    And I press "Save and return to course"
    Then I should see "Test Forum"

  Scenario: In single reaction mode, reacting with a new emoji replaces the previous one
    Given the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | allowmultiplereactions |
      | Test Forum | C1     | 1       | 0                      |
    And the following "mod_forum > discussions" exist:
      | user     | forum      | name      | message   |
      | student1 | Test Forum | Test Post | A message |
    And the following "local_reactions > reactions" exist:
      | user     | post      | emoji    |
      | student1 | Test Post | thumbsup |
    When I log in as "student1"
    And I am on the "Test Forum" "forum activity" page
    And I follow "Test Post"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 1
    When I open the reactions picker
    And I react with "heart"
    Then the "heart" reaction count should be 1
    And I should not see the "thumbsup" reaction pill
