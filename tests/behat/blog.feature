@local @local_reactions @javascript
Feature: Blog entry reactions
  As a user with blog:view permission I want to react to Moodle blog entries
  with emoji so that bloggers can see engagement on their posts.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | author   | Author    | User     | author@example.com   |
      | reader1  | Reader    | One      | reader1@example.com  |
      | reader2  | Reader    | Two      | reader2@example.com  |
    And the following "core_blog > entries" exist:
      | subject         | body                              | user    |
      | First blog post | Some thoughts for the first post. | author  |
      | Second blog post | Another day, another blog entry. | author  |
    And I change the window size to "large"

  Scenario: No reactions bar when blog reactions are disabled
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 0 | local_reactions |
    And I log in as "reader1"
    When I am on the blog entry page for "First blog post"
    Then ".local-reactions-bar" "css_element" should not exist

  Scenario: Reactions bar appears on a blog entry when blog reactions are enabled
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 1 | local_reactions |
    And I log in as "reader1"
    When I am on the blog entry page for "First blog post"
    And I wait for reactions to load
    Then "div.blog_entry [data-region='reactions-bar']" "css_element" should exist

  Scenario: Add then remove a reaction on a blog entry
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 1 | local_reactions |
    And I log in as "reader1"
    And I am on the blog entry page for "First blog post"
    And I wait for reactions to load
    # No reactions yet.
    And I should not see the "thumbsup" reaction pill
    # Add a thumbsup.
    When I open the reactions picker
    And I react with "thumbsup"
    Then the "thumbsup" reaction count should be 1
    # Click the active pill to remove it.
    When I click on ".local-reactions-pill[data-emoji='thumbsup']" "css_element"
    And I wait for live reactions to load
    Then I should not see the "thumbsup" reaction pill

  Scenario: Multiple users react to the same blog entry
    Given the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 1 | local_reactions |
    And the following "local_reactions > reactions" exist:
      | user    | blogentry       | emoji    |
      | reader1 | First blog post | thumbsup |
      | reader2 | First blog post | thumbsup |
      | author  | First blog post | heart    |
    And I log in as "reader1"
    When I am on the blog entry page for "First blog post"
    And I wait for reactions to load
    Then the "thumbsup" reaction count should be 2
    And the "heart" reaction count should be 1

  Scenario: Forum and blog settings are independent - forum on, blog off
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | author  | C1     | student |
      | reader1 | C1     | student |
    And the following "activities" exist:
      | activity | name      | course | type    | idnumber |
      | forum    | News Feed | C1     | general | forum1   |
    And the following "local_reactions > enabled forums" exist:
      | forum     | course | enabled | compactview_list | compactview_discuss |
      | News Feed | C1     | 1       | 0                | 0                   |
    And the following "mod_forum > discussions" exist:
      | user   | forum     | name           | message           |
      | author | News Feed | Forum greeting | Hello the forum!  |
    And the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 0 | local_reactions |
    And I log in as "reader1"
    # Forum page should still render the reactions bar.
    When I am on the "News Feed" "forum activity" page
    And I follow "Forum greeting"
    And I wait for reactions to load
    Then "article [data-region='reactions-bar']" "css_element" should exist
    # Blog page should have no bar because enabledblog is off.
    When I am on the blog entry page for "First blog post"
    Then ".local-reactions-bar" "css_element" should not exist
