@local @local_reactions @javascript
Feature: Reactions on the forum grading page
  When whole forum grading is enabled, reactions should be displayed
  read-only alongside each post in the grading panel.

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
    And the following "activity" exists:
      | activity    | forum          |
      | name        | Graded Forum   |
      | course      | C1             |
      | idnumber    | gradedforum1   |
      | grade_forum | 100            |
      | scale       | 100            |
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    And the following "local_reactions > enabled forums" exist:
      | forum        | course | enabled | compactview_list | compactview_discuss |
      | Graded Forum | C1     | 1       | 0                | 0                   |
    And the following "mod_forum > discussions" exist:
      | user     | forum        | name             | message               |
      | student1 | Graded Forum | Student One Post | Hello from Student 1! |
      | student2 | Graded Forum | Student Two Post | Hello from Student 2! |
    And the following "local_reactions > reactions" exist:
      | user     | post             | emoji    |
      | teacher1 | Student One Post | thumbsup |
      | student2 | Student One Post | thumbsup |
      | student1 | Student One Post | heart    |
      | student1 | Student Two Post | laugh    |
      | teacher1 | Student Two Post | laugh    |
    And I change the window size to "large"

  Scenario: Reactions appear in the whole forum grading panel
    Given I am on the "Graded Forum" "forum activity" page logged in as teacher1
    And I press "Grade users"
    And I wait for grading reactions to load
    Then "[data-region='reactions-bar']" "css_element" should exist
    And "[data-emoji='thumbsup']" "css_element" should exist
