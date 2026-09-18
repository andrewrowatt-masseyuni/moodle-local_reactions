@local @local_reactions @javascript
Feature: Show who reacted
  As a member of a forum with "Show who reacted" turned on
  I want each reaction to name the people behind it
  So that I can see who is engaging, without the full list being exposed to students.

  # Reactions on "Hello everyone", oldest first:
  #   Ann 1001, Bob 1002, Cid 1003, Dee 1004, Jenny (teacher) 1005.
  # Tooltips list the most recent reaction first, so the order is Jenny, Dee, Cid, Bob, Ann.
  # Eve never reacts, so she is the "uninvolved student" viewer.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | jenny    | Jenny     | Smith    | jenny@example.com    |
      | student1 | Ann       | One      | student1@example.com |
      | student2 | Bob       | Two      | student2@example.com |
      | student3 | Cid       | Three    | student3@example.com |
      | student4 | Dee       | Four     | student4@example.com |
      | student5 | Eve       | Five     | student5@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | jenny    | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
    And the following "activities" exist:
      | activity | name          | course | type    | idnumber |
      | forum    | Introductions | C1     | general | intros1  |
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    And the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | shownames |
      | Introductions | C1     | 1       | 1         |
    And the following "mod_forum > discussions" exist:
      | user  | forum         | name           | message              |
      | jenny | Introductions | Hello everyone | Welcome to the forum |
    And the following "local_reactions > reactions" exist:
      | user     | post           | emoji    | timecreated |
      | student1 | Hello everyone | thumbsup | 1001        |
      | student2 | Hello everyone | thumbsup | 1002        |
      | student3 | Hello everyone | thumbsup | 1003        |
      | student4 | Hello everyone | thumbsup | 1004        |
      | jenny    | Hello everyone | thumbsup | 1005        |
    And I change the window size to "large"

  Scenario: A student sees three names and a count of the rest, most recent first
    Given I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "Jenny (Teacher), Dee, Cid, and 2 others"

  Scenario: A student who reacted is named first as "You" without using up a slot
    Given I log in as "student1"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "You, Jenny (Teacher), Dee, Cid, and 1 other"

  Scenario: A teacher sees every name up to the site-wide allowance
    Given I log in as "jenny"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "You, Dee, Cid, Bob, Ann"

  Scenario: The site-wide allowance caps what a teacher sees
    Given the following config values are set as admin:
      | shownameslimit | 2 | local_reactions |
    And I log in as "jenny"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "You, Dee, Cid, and 2 others"

  Scenario: Reactions stay anonymous while the setting is off
    Given the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | shownames |
      | Introductions | C1     | 1       | 0         |
    And I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 5
    And the "thumbsup" reaction should name nobody

  Scenario: The discussion list names the people who reacted anywhere in the discussion
    Given I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "Jenny (Teacher), Dee, Cid, and 2 others"

  Scenario: The compact pill names everyone behind its total
    Given the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | shownames | compactview_discuss |
      | Introductions | C1     | 1       | 1         | 1                   |
    And the following "local_reactions > reactions" exist:
      | user     | post           | emoji | timecreated |
      | student1 | Hello everyone | heart | 1006        |
    And I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    Then the compact reaction pill should list "Ann, Jenny (Teacher), Dee, and 2 others"

  Scenario: Hovering a pill opens a Bootstrap tooltip carrying the names
    Given I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello everyone"
    And I wait for reactions to load
    When I hover ".local-reactions-pill-wrapper" "css_element"
    Then I should see "Jenny (Teacher), Dee, Cid, and 2 others" in the ".tooltip-inner" "css_element"

  Scenario: Names survive a second visit, when the counts come back from the cache unchanged
    Given the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | shownames | compactview_list |
      | Introductions | C1     | 1       | 1         | 1                |
    And I log in as "jenny"
    And I am on the "Introductions" "forum activity" page
    And I wait for reactions to load
    And the compact reaction pill should list "You, Dee, Cid, Bob, Ann"
    When I reload the page
    And I wait for reactions to load
    Then the compact reaction pill should list "You, Dee, Cid, Bob, Ann"

  Scenario: Individual pill names also survive a cached second visit
    Given I log in as "student5"
    And I am on the "Introductions" "forum activity" page
    And I wait for reactions to load
    And the "thumbsup" reaction should list "Jenny (Teacher), Dee, Cid, and 2 others"
    When I reload the page
    And I wait for reactions to load
    Then the "thumbsup" reaction should list "Jenny (Teacher), Dee, Cid, and 2 others"
