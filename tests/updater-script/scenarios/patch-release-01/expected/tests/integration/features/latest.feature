Feature: Testing the latest endpoint

  Scenario: Get latest stable version
    Given I want to know the latest stable release
    When I send a request latest.php
    Then The JSON response is non-empty
    And Version "33.0.1" is the latest release
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-33.0.1.zip"

  Scenario: Get latest beta version
    Given I want to know the latest beta release
    When I send a request latest.php
    Then The JSON response is non-empty
    And Version "34.0.0 RC5" is the latest release
    And URL to download is "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc5.zip"
