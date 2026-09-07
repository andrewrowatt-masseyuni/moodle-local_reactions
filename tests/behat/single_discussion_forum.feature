@local @local_reactions @javascript
Feature: Reactions on a "A single simple discussion" forum
  As a student I want to react to the post of a single simple discussion forum,
  which renders its one discussion on the forum page itself rather than a discussion list.

  # A single simple discussion forum creates its discussion when the forum is created, and the
  # first post takes its subject from the forum name. mod/forum/view.php then renders that
  # discussion inline with the same post markup discuss.php uses, so reactions have to behave
  # exactly as they do on a discussion page: interactive bars, and the discussion display
  # setting rather than the discussion list one.
  #
  # Reaction totals:
  #   Class notices (compactview_list=1, compactview_discuss=0): thumbsup:2, laugh:1 (normal view)
  #   Reading list  (compactview_list=0, compactview_discuss=1): heart:2 = 2 total (compact view)

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activities" exist:
      | activity | name          | course | type   | idnumber |
      | forum    | Class notices | C1     | single | notices1 |
      | forum    | Reading list  | C1     | single | reading1 |
    # Enable the reactions plugin globally.
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    # The two display settings are deliberately opposites, so each forum proves that the
    # discussion setting is the one that applies on a single simple discussion forum.
    And the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | compactview_list | compactview_discuss |
      | Class notices | C1     | 1       | 1                | 0                   |
      | Reading list  | C1     | 1       | 0                | 1                   |
    # The auto-created post carries the forum name as its subject.
    And the following "local_reactions > reactions" exist:
      | user     | post          | emoji    |
      | student1 | Class notices | thumbsup |
      | student2 | Class notices | thumbsup |
      | student3 | Class notices | laugh    |
      | student1 | Reading list  | heart    |
      | student2 | Reading list  | heart    |

    And I change the window size to "large"

  Scenario: Reactions render on the forum page of a single simple discussion forum
    Given I log in as "student3"
    And I am on the "Class notices" "forum activity" page
    And I wait for reactions to load
    # One bar only: the single post shown on the page.
    Then 1 reactions bars should be rendered
    And the "thumbsup" reaction count should be 2
    And the "laugh" reaction count should be 1
    # Emojis with zero reactions should not show pills.
    And I should not see the "heart" reaction pill
    And I should not see the "celebrate" reaction pill

  Scenario: Student can react to the post of a single simple discussion forum
    Given I log in as "student3"
    And I am on the "Class notices" "forum activity" page
    And I wait for reactions to load
    When I open the reactions picker
    And I react with "celebrate"
    Then the "celebrate" reaction count should be 1
    # Existing counts should be unchanged.
    And the "thumbsup" reaction count should be 2
    And the "laugh" reaction count should be 1

  Scenario: A single simple discussion forum uses the discussion compact view setting
    Given I log in as "student3"
    And I am on the "Reading list" "forum activity" page
    And I wait for reactions to load
    # compactview_discuss is on for this forum, so the bar collapses to a single total pill.
    Then the compact reaction total should be 2
