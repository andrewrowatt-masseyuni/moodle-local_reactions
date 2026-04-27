@local @local_reactions @javascript
Feature: Site-wide single vs multiple reactions for blog posts
  As an admin I want to control whether users can react to a blog post with more
  than one emoji, with the multi-reaction setting locking on once it has been used.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | author   | Author    | User     | author@example.com   |
      | reader1  | Reader    | One      | reader1@example.com  |
    And the following "core_blog > entries" exist:
      | subject         | body                              | user    |
      | First blog post | Some thoughts for the first post. | author  |
    And the following config values are set as admin:
      | enabled     | 1 | local_reactions |
      | enabledblog | 1 | local_reactions |
    And I change the window size to "large"

  Scenario: With multiple reactions disabled, a new emoji replaces the previous one
    Given the following config values are set as admin:
      | allowmultiplereactionsblog | 0 | local_reactions |
    And I log in as "reader1"
    And I am on the blog entry page for "First blog post"
    And I wait for reactions to load
    When I open the reactions picker
    And I react with "thumbsup"
    Then the "thumbsup" reaction count should be 1
    When I open the reactions picker
    And I react with "heart"
    Then the "heart" reaction count should be 1
    And I should not see the "thumbsup" reaction pill

  Scenario: With multiple reactions enabled, a user can stack more than one emoji
    Given the following config values are set as admin:
      | allowmultiplereactionsblog | 1 | local_reactions |
    And I log in as "reader1"
    And I am on the blog entry page for "First blog post"
    And I wait for reactions to load
    When I open the reactions picker
    And I react with "thumbsup"
    Then the "thumbsup" reaction count should be 1
    When I open the reactions picker
    And I react with "heart"
    Then the "heart" reaction count should be 1
    And the "thumbsup" reaction count should be 1

  Scenario: Multiple reactions setting is locked once a user stacks emoji on one post
    Given the following config values are set as admin:
      | allowmultiplereactionsblog | 1 | local_reactions |
    And the following "local_reactions > reactions" exist:
      | user    | blogentry       | emoji    |
      | reader1 | First blog post | thumbsup |
      | reader1 | First blog post | heart    |
    And I log in as "admin"
    When I set the following administration settings values:
      | Enable multiple reactions per-user per blog post | 0 |
    Then I should see "This setting cannot be disabled because there are multiple reactions by a single user on a single blog post."

  Scenario: Multiple reactions setting can be disabled when no user has stacked emoji
    Given the following config values are set as admin:
      | allowmultiplereactionsblog | 1 | local_reactions |
    And the following "local_reactions > reactions" exist:
      | user    | blogentry       | emoji    |
      | reader1 | First blog post | thumbsup |
    And I log in as "admin"
    When I set the following administration settings values:
      | Enable multiple reactions per-user per blog post | 0 |
    Then I should not see "This setting cannot be disabled"
