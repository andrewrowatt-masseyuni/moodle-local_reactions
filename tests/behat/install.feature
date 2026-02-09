@local @local_reactions
Feature: Basic tests for Reactions

  @javascript
  Scenario: Plugin local_reactions appears in the list of installed additional plugins
    Given I log in as "admin"
    When I navigate to "Plugins > Plugins overview" in site administration
    And I follow "Additional plugins"
    Then I should see "Reactions"
    And I should see "local_reactions"
