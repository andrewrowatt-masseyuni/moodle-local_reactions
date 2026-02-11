@local @local_reactions @javascript
Feature: Reactions IndexedDB caching
  As a user I want reactions to load instantly from a local cache
  and animate differences when fresh data arrives from the server.

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
    And the following "local_reactions > enabled forums" exist:
      | forum      | course | enabled | compactview_list | compactview_discuss |
      | Test Forum | C1     | 1       | 0                | 0                   |
    And the following "mod_forum > discussions" exist:
      | user     | forum      | name       | message     |
      | student1 | Test Forum | Test Topic | Hello world |
    And the following "local_reactions > reactions" exist:
      | user     | post       | emoji    |
      | student1 | Test Topic | thumbsup |
      | student2 | Test Topic | thumbsup |

  Scenario: Reactions are served from cache on second page visit
    # First visit: reactions are loaded via AJAX and cached in IndexedDB.
    Given I log in as "student1"
    And I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for live reactions to load
    Then the "thumbsup" reaction count should be 2
    And the reactions bar should be from "live"
    # Navigate away and come back - reactions should render from cache first.
    When I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 2
    # Wait for live data to arrive and replace cache render.
    And I wait for live reactions to load
    And the "thumbsup" reaction count should be 2

  Scenario: Cache is updated after toggling a reaction
    # Load page to populate the cache.
    Given I log in as "student1"
    And I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for live reactions to load
    Then the "thumbsup" reaction count should be 2
    # Toggle a new reaction.
    When I open the reactions picker
    And I react with "heart"
    Then the "heart" reaction count should be 1
    # Navigate away and come back - cache should include the new reaction.
    When I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for reactions to load
    Then the "heart" reaction count should be 1
    And the "thumbsup" reaction count should be 2

  Scenario: Discussion list reactions are cached between visits
    # First visit to forum list page.
    Given I log in as "student1"
    And I am on the "Test Forum" "forum activity" page
    And I wait for live reactions to load
    Then "[data-emoji='thumbsup'][data-count='2']" "css_element" should exist in the "Test Topic" "table_row"
    # Navigate away and come back.
    When I am on "Course 1" course homepage
    And I am on the "Test Forum" "forum activity" page
    And I wait for reactions to load
    Then "[data-emoji='thumbsup'][data-count='2']" "css_element" should exist in the "Test Topic" "table_row"

  Scenario: Fresh data updates stale cache with correct counts
    # Populate cache by visiting the discussion page.
    Given I log in as "student1"
    And I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for live reactions to load
    Then the "thumbsup" reaction count should be 2
    # Another user adds a reaction in the background.
    And the following "local_reactions > reactions" exist:
      | user     | post       | emoji    |
      | teacher1 | Test Topic | thumbsup |
    # Revisit - cache has count=2, but fresh data has count=3.
    When I am on the "Test Forum" "forum activity" page
    And I follow "Test Topic"
    And I wait for live reactions to load
    # After fresh data arrives, the count should be updated.
    Then the "thumbsup" reaction count should be 3
