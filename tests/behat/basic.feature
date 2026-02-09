@local @local_reactions @javascript
Feature: Forum reactions and reporting
  As a teacher and student I want to react to forum posts with emoji
  and verify that reaction counts and reports are accurate.

  # Reaction totals:
  #   News "Welcome to the course": thumbsup:1, laugh:1, think:1 = 3 total (compact view)
  #   Intros "Hello from Student One": thumbsup:7, laugh:2, think:1, thanks:1 = 11 total (normal view)
  #   Intros "Hello from Student Two": heart:4, laugh:4, think:1, thanks:2 = 11 total (normal view)
  #
  # Report expected:
  #   Total reactions: 25, Total posts: 3, Ratio: 8.33:1
  #   Active reactors: 11, Active posters: 3

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname | email                 |
      | teacher1  | Teacher   | One      | teacher1@example.com  |
      | student1  | Student   | One      | student1@example.com  |
      | student2  | Student   | Two      | student2@example.com  |
      | student3  | Student   | Three    | student3@example.com  |
      | student4  | Student   | Four     | student4@example.com  |
      | student5  | Student   | Five     | student5@example.com  |
      | student6  | Student   | Six      | student6@example.com  |
      | student7  | Student   | Seven    | student7@example.com  |
      | student8  | Student   | Eight    | student8@example.com  |
      | student9  | Student   | Nine     | student9@example.com  |
      | student10 | Student   | Ten      | student10@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | C1     | editingteacher |
      | student1  | C1     | student        |
      | student2  | C1     | student        |
      | student3  | C1     | student        |
      | student4  | C1     | student        |
      | student5  | C1     | student        |
      | student6  | C1     | student        |
      | student7  | C1     | student        |
      | student8  | C1     | student        |
      | student9  | C1     | student        |
      | student10 | C1     | student        |
    And the following "activities" exist:
      | activity | name          | course | type    | idnumber |
      | forum    | News          | C1     | news    | news1    |
      | forum    | Introductions | C1     | general | intros1  |
    # Enable the reactions plugin globally.
    And the following config values are set as admin:
      | enabled | 1 | local_reactions |
    # Enable reactions per-forum: News uses compact view, Introductions uses normal view.
    And the following "local_reactions > enabled forums" exist:
      | forum         | course | enabled | compactview_list | compactview_discuss |
      | News          | C1     | 1       | 1                | 1                   |
      | Introductions | C1     | 1       | 0                | 0                   |
    # Create forum discussions.
    And the following "mod_forum > discussions" exist:
      | user     | forum         | name                   | message                  |
      | teacher1 | News          | Welcome to the course  | Hello everyone, welcome! |
      | student1 | Introductions | Hello from Student One | Hi, I'm Student One!     |
      | student2 | Introductions | Hello from Student Two | Hi, I'm Student Two!     |
    # News forum: 3 students react (thumbsup:1, laugh:1, think:1).
    And the following "local_reactions > reactions" exist:
      | user     | post                  | emoji    |
      | student1 | Welcome to the course | thumbsup |
      | student2 | Welcome to the course | laugh    |
      | student3 | Welcome to the course | think    |
    # Introductions: teacher + 10 students react to Student One's post.
    # thumbsup:7, laugh:2, think:1, thanks:1 = 11 total.
    And the following "local_reactions > reactions" exist:
      | user      | post                   | emoji    |
      | teacher1  | Hello from Student One | thumbsup |
      | student1  | Hello from Student One | thumbsup |
      | student2  | Hello from Student One | thumbsup |
      | student3  | Hello from Student One | thumbsup |
      | student4  | Hello from Student One | thumbsup |
      | student5  | Hello from Student One | thumbsup |
      | student6  | Hello from Student One | thumbsup |
      | student7  | Hello from Student One | laugh    |
      | student8  | Hello from Student One | laugh    |
      | student9  | Hello from Student One | think    |
      | student10 | Hello from Student One | thanks   |
    # Introductions: teacher + 10 students react to Student Two's post.
    # heart:4, laugh:4, think:1, thanks:2 = 11 total.
    And the following "local_reactions > reactions" exist:
      | user      | post                   | emoji  |
      | teacher1  | Hello from Student Two | heart  |
      | student1  | Hello from Student Two | heart  |
      | student2  | Hello from Student Two | heart  |
      | student3  | Hello from Student Two | heart  |
      | student4  | Hello from Student Two | laugh  |
      | student5  | Hello from Student Two | laugh  |
      | student6  | Hello from Student Two | laugh  |
      | student7  | Hello from Student Two | laugh  |
      | student8  | Hello from Student Two | think  |
      | student9  | Hello from Student Two | thanks |
      | student10 | Hello from Student Two | thanks |

  Scenario: Student can react to a post via the emoji picker
    Given I log in as "student4"
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello from Student One"
    And I wait for reactions to load
    # Existing counts: thumbsup:7, laugh:2, think:1, thanks:1.
    Then the "thumbsup" reaction count should be 7
    And the "laugh" reaction count should be 2
    # Emojis with zero reactions should not show pills.
    And I should not see the "celebrate" reaction pill
    And I should not see the "heart" reaction pill
    # Add a new celebrate reaction via the picker.
    When I open the reactions picker
    And I react with "celebrate"
    Then the "celebrate" reaction count should be 1
    # Existing counts should be unchanged.
    And the "thumbsup" reaction count should be 7
    And the "laugh" reaction count should be 2

  Scenario: News forum displays compact reaction counts on discussion page and list page
    Given I log in as "student1"
    # Discussion page: compact pill should show total of 3.
    And I am on the "News" "forum activity" page
    And I follow "Welcome to the course"
    And I wait for reactions to load
    Then the compact reaction total should be 3
    # Forum list page: compact pill should also show 3.
    And I am on the "News" "forum activity" page
    And I wait for reactions to load
    Then the compact reaction total should be 3

  Scenario: Introductions forum displays normal reaction counts on discussion and list pages
    Given I log in as "student3"
    # Discussion page for Student One's post: thumbsup:7, laugh:2, think:1, thanks:1.
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello from Student One"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 7
    And the "laugh" reaction count should be 2
    And the "think" reaction count should be 1
    And the "thanks" reaction count should be 1
    # No heart or celebrate pills on this post.
    And I should not see the "heart" reaction pill
    And I should not see the "celebrate" reaction pill
    # Discussion page for Student Two's post: heart:4, laugh:4, think:1, thanks:2.
    And I am on the "Introductions" "forum activity" page
    And I follow "Hello from Student Two"
    And I wait for reactions to load
    Then the "heart" reaction count should be 4
    And the "laugh" reaction count should be 4
    And the "think" reaction count should be 1
    And the "thanks" reaction count should be 2
    # No thumbsup or celebrate pills on this post.
    And I should not see the "thumbsup" reaction pill
    And I should not see the "celebrate" reaction pill
    # Forum list page: check emoji counts appear in correct discussion rows.
    And I am on the "Introductions" "forum activity" page
    And I wait for reactions to load
    Then "[data-emoji='thumbsup'][data-count='7']" "css_element" should exist in the "Hello from Student One" "table_row"
    And "[data-emoji='laugh'][data-count='2']" "css_element" should exist in the "Hello from Student One" "table_row"
    And "[data-emoji='heart'][data-count='4']" "css_element" should exist in the "Hello from Student Two" "table_row"
    And "[data-emoji='laugh'][data-count='4']" "css_element" should exist in the "Hello from Student Two" "table_row"
    And "[data-emoji='thanks'][data-count='2']" "css_element" should exist in the "Hello from Student Two" "table_row"

  Scenario: Teacher can access the reactions report with correct statistics
    Given I log in as "teacher1"
    And I am on the reactions report for "Course 1"
    # Engagement overview.
    Then I should see "Engagement overview"
    And I should see "Total reactions"
    And I should see "25"
    And I should see "Total posts"
    And I should see "3"
    And I should see "Reactions to posts ratio"
    And I should see "8.33:1"
    # Active participation.
    And I should see "Active participation"
    And I should see "Active reactors"
    And I should see "11"
    And I should see "Active posters"
    # Posts needing attention: all posts have reactions.
    And I should see "All posts have received at least one reaction!"
    # Top performers: all 3 posts should appear.
    And I should see "Top performers this week"
    And I should see "Welcome to the course"
    And I should see "Hello from Student One"
    And I should see "Hello from Student Two"

  Scenario: Student cannot access the reactions report
    # The report navigation link should not exist in page source for students.
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    Then "Reactions report" "link" should not exist
    # Verify teacher does have the link (may be in More menu).
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    Then "Reactions report" "link" should exist
