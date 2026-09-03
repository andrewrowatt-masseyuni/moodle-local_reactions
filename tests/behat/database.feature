@local @local_reactions @javascript
Feature: Database activity reactions
  As a teacher and student I want to react to database activity entries with emoji
  so that contributors can see engagement on what they posted.

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
      | activity | name       | course | idnumber |
      | data     | Field trip | C1     | data1    |
    And the following "mod_data > fields" exist:
      | database | type | name  |
      | data1    | text | Title |
    And the following "mod_data > entries" exist:
      | database | user     | Title       |
      | data1    | student1 | First entry |
      | data1    | student2 | Second entry |
    And I change the window size to "large"

  Scenario: No reactions bar when database reactions are disabled site-wide
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 0 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And I log in as "student1"
    When I am on the "Field trip" "data activity" page
    Then ".local-reactions-bar" "css_element" should not exist

  Scenario: No reactions bar when the activity toggle is off
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 0       |
    And I log in as "student1"
    When I am on the "Field trip" "data activity" page
    Then ".local-reactions-bar" "css_element" should not exist

  Scenario: Reactions bars appear on every entry in the list view
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And I log in as "student1"
    When I am on the "Field trip" "data activity" page
    And I wait for reactions to load
    Then ".defaulttemplate-listentry [data-region='reactions-bar']" "css_element" should exist
    And 2 reactions bars should be rendered

  Scenario: Add then remove a reaction on a database entry
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And I log in as "student2"
    And I am on the "Field trip" "data activity" page
    And I wait for reactions to load
    And I should not see the "thumbsup" reaction pill
    When I open the reactions picker
    And I react with "thumbsup"
    Then the "thumbsup" reaction count should be 1
    When I click on ".local-reactions-pill[data-emoji='thumbsup']" "css_element"
    And I wait for live reactions to load
    Then I should not see the "thumbsup" reaction pill

  Scenario: Existing reactions from several users are counted on the right entry
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And the following "local_reactions > reactions" exist:
      | user     | dataentry   | emoji    |
      | student1 | First entry | thumbsup |
      | student2 | First entry | thumbsup |
      | teacher1 | First entry | heart    |
      | student1 | Second entry | heart   |
    And I log in as "student1"
    When I am on the "Field trip" "data activity" page
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 2
    And the "heart" reaction count should be 1

  Scenario: Reactions render in the single entry view
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And the following "local_reactions > reactions" exist:
      | user     | dataentry   | emoji    |
      | student2 | First entry | thumbsup |
    And I log in as "student1"
    And I am on the "Field trip" "data activity" page
    When I select "Single view" from the "jump" singleselect
    And I wait for reactions to load
    Then "#defaulttemplate-single [data-region='reactions-bar']" "css_element" should exist

  Scenario: A custom template places the bar wherever the anchor is
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And the following "mod_data > templates" exist:
      | database | name         | content                                                                                                           |
      | data1    | listtemplate | <div class="customentry">[[Title]]<div data-region="local-reactions-anchor" data-recordid="##id##"></div></div>    |
    And I log in as "student1"
    When I am on the "Field trip" "data activity" page
    And I wait for reactions to load
    Then ".customentry [data-region='local-reactions-anchor'] [data-region='reactions-bar']" "css_element" should exist
    And 2 reactions bars should be rendered

  Scenario: A teacher turns reactions on from the database activity settings form
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 1 | local_reactions |
    And I am on the "Field trip" "data activity editing" page logged in as teacher1
    When I expand all fieldsets
    Then I should see "Reactions"
    # The peer-grading option belongs to the forum's grading panel and is not offered here.
    And I should not see "Only show peer reactions when grading"
    And I set the field "Enable emoji reactions" to "1"
    And I press "Save and display"
    And I log out
    And I log in as "student1"
    And I am on the "Field trip" "data activity" page
    And I wait for reactions to load
    Then ".defaulttemplate-listentry [data-region='reactions-bar']" "css_element" should exist
    And 2 reactions bars should be rendered

  Scenario: The reactions settings are hidden when database reactions are off site-wide
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 0 | local_reactions |
    And I am on the "Field trip" "data activity editing" page logged in as teacher1
    When I expand all fieldsets
    Then I should not see "Enable emoji reactions"

  Scenario: Forum and database settings are independent - forum on, database off
    Given the following "activities" exist:
      | activity | name      | course | type    | idnumber |
      | forum    | News Feed | C1     | general | forum1   |
    And the following "local_reactions > enabled forums" exist:
      | forum     | course | enabled | compactview_list | compactview_discuss |
      | News Feed | C1     | 1       | 0                | 0                   |
    And the following "local_reactions > enabled databases" exist:
      | data       | course | enabled |
      | Field trip | C1     | 1       |
    And the following "mod_forum > discussions" exist:
      | user     | forum     | name           | message          |
      | student1 | News Feed | Forum greeting | Hello the forum! |
    And the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enableddata | 0 | local_reactions |
    And I log in as "student1"
    When I am on the "News Feed" "forum activity" page
    And I follow "Forum greeting"
    And I wait for reactions to load
    Then "article [data-region='reactions-bar']" "css_element" should exist
    When I am on the "Field trip" "data activity" page
    Then ".local-reactions-bar" "css_element" should not exist
